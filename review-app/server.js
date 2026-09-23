const express = require('express');
const { Pool } = require('pg');
const { esc, layout, scoreClass, renderReviewDetail } = require('./render');

const PORT = process.env.PORT || 4400;
const APP_PASSWORD = process.env.REVIEW_APP_PASSWORD || 'changeme';

const pool = new Pool({
  host: process.env.DB_HOST || 'n8n-db',
  port: process.env.DB_PORT || 5432,
  database: process.env.DB_NAME || 'n8n',
  user: process.env.DB_USER || 'n8n',
  password: process.env.DB_PASSWORD || 'changeme'
});

const TARGETS = {
  local: {
    label: 'Local',
    url: process.env.LOCAL_WP_URL || 'http://wordpress/wp-json/rgh/v1/publish-review',
    // No hardcoded fallback - an unconfigured key should fail closed (see the
    // "Missing config" check in the publish route), not silently work with a
    // stale value that used to be committed to git.
    apiKey: process.env.LOCAL_WP_API_KEY || ''
  },
  prod: {
    label: 'Production',
    url: process.env.PROD_WP_URL || 'https://reviewgeekhub.com/wp-json/rgh/v1/publish-review',
    apiKey: process.env.PROD_WP_API_KEY || ''
  }
};

const app = express();
app.use(express.urlencoded({ extended: true }));

// Single shared-password basic auth - this app can trigger real publishes
// (including to production), so even on a local-only tool it shouldn't be
// wide open to anything else on the same network.
app.use((req, res, next) => {
  const auth = req.headers.authorization;
  if (auth && auth.startsWith('Basic ')) {
    const [, password] = Buffer.from(auth.slice(6), 'base64').toString().split(':');
    if (password === APP_PASSWORD) return next();
  }
  res.set('WWW-Authenticate', 'Basic realm="Review App"');
  res.status(401).send('Authentication required.');
});

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toISOString().replace('T', ' ').slice(0, 16) + ' UTC';
}

app.get('/', async (req, res, next) => {
  try {
    const { rows: awaiting } = await pool.query(
      `SELECT id, product_name, brand, category, review_payload, discovered_at
       FROM rgh.product_queue WHERE status = 'awaiting_review' ORDER BY discovered_at ASC`
    );
    const { rows: recent } = await pool.query(
      `SELECT id, product_name, status, local_wp_link, prod_wp_link, processed_at
       FROM rgh.product_queue WHERE status IN ('published') OR (status = 'needs_review' AND processed_at IS NOT NULL)
       ORDER BY processed_at DESC LIMIT 10`
    );

    const cards = awaiting.map(row => {
      const p = row.review_payload || {};
      const sc = scoreClass(p.score);
      return `<div class="card">
        ${p.image_url ? `<img src="${esc(p.image_url)}" alt="">` : ''}
        <div><span class="pill">${esc(row.category || '')}</span><span class="pill">${esc(row.brand || '')}</span></div>
        <h3><a href="/review/${row.id}">${esc(p.title || row.product_name)}</a></h3>
        <div class="meta">Score: <span class="score ${sc}">${esc(p.score ?? '—')}/10</span> &middot; queued ${fmtDate(row.discovered_at)}</div>
      </div>`;
    }).join('') || '<div class="empty">Nothing waiting for review right now.</div>';

    const recentRows = recent.map(row => {
      const link = row.local_wp_link || row.prod_wp_link || '';
      return `<div class="meta">#${row.id} ${esc(row.product_name)} — ${esc(row.status)}${link ? ` — <a href="${esc(link)}" target="_blank">view</a>` : ''} (${fmtDate(row.processed_at)})</div>`;
    }).join('');

    res.send(layout('Review Queue', `
      <h1>Awaiting review (${awaiting.length})</h1>
      ${cards}
      <section><h2>Recent activity</h2>${recentRows || '<div class="meta">Nothing yet.</div>'}</section>
    `));
  } catch (e) { next(e); }
});

app.get('/review/:id', async (req, res, next) => {
  try {
    const { rows } = await pool.query(
      `SELECT id, product_name, brand, category, status, review_payload FROM rgh.product_queue WHERE id = $1`,
      [req.params.id]
    );
    if (!rows.length) return res.status(404).send(layout('Not found', '<p>No such item.</p>'));
    const row = rows[0];
    const p = row.review_payload || {};

    if (row.status !== 'awaiting_review') {
      return res.send(layout(p.title || row.product_name, `
        <p><a href="/">&larr; back</a></p>
        <h1>${esc(p.title || row.product_name)}</h1>
        <p class="meta">Status: <strong>${esc(row.status)}</strong> - this item is no longer awaiting review.</p>
      `));
    }

    res.send(layout(p.title || row.product_name, renderReviewDetail(row, p)));
  } catch (e) { next(e); }
});

app.post('/review/:id/publish', async (req, res, next) => {
  const target = TARGETS[req.body.target];
  if (!target) return res.status(400).send('Unknown target.');
  try {
    const { rows } = await pool.query(
      `SELECT id, review_payload FROM rgh.product_queue WHERE id = $1 AND status = 'awaiting_review'`,
      [req.params.id]
    );
    if (!rows.length) return res.status(404).send(layout('Not found', '<p>Item not found or already handled. <a href="/">Back</a></p>'));
    const payload = rows[0].review_payload;

    if (!target.apiKey) {
      return res.status(400).send(layout('Missing config', `<p>No API key configured for ${esc(target.label)} - set the env var before publishing there. <a href="/review/${req.params.id}">Back</a></p>`));
    }

    const wpRes = await fetch(target.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-RGH-Api-Key': target.apiKey },
      body: JSON.stringify(payload)
    });
    const body = await wpRes.json().catch(() => ({}));

    if (!wpRes.ok) {
      return res.status(502).send(layout('Publish failed', `<p>WordPress (${esc(target.label)}) returned ${wpRes.status}: ${esc(JSON.stringify(body))}</p><p><a href="/review/${req.params.id}">Back</a></p>`));
    }

    const col = req.body.target === 'local' ? ['local_wp_post_id', 'local_wp_link'] : ['prod_wp_post_id', 'prod_wp_link'];
    await pool.query(
      `UPDATE rgh.product_queue SET status = 'published', ${col[0]} = $1, ${col[1]} = $2, processed_at = now(), error_message = NULL WHERE id = $3`,
      [body.post_id || null, body.link || null, req.params.id]
    );
    res.redirect('/');
  } catch (e) { next(e); }
});

app.post('/review/:id/reject', async (req, res, next) => {
  try {
    await pool.query(
      `UPDATE rgh.product_queue SET status = 'needs_review', error_message = $1, processed_at = now() WHERE id = $2 AND status = 'awaiting_review'`,
      [req.body.note || 'Rejected via review app', req.params.id]
    );
    res.redirect('/');
  } catch (e) { next(e); }
});

app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).send(layout('Error', `<pre>${esc(err.message)}</pre>`));
});

app.listen(PORT, () => console.log(`Review app listening on :${PORT}`));

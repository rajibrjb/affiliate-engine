function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function layout(title, body) {
  return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>${esc(title)}</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; background: #fafafa; }
  a { color: #2563eb; }
  h1 { font-size: 1.4rem; }
  .card { background: #fff; border: 1px solid #e2e2e2; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
  .card img { max-width: 160px; border-radius: 6px; display: block; margin-bottom: .5rem; }
  .meta { color: #666; font-size: .9rem; }
  .score { font-weight: 700; font-size: 1.1rem; }
  .score.low { color: #b91c1c; }
  .score.mid { color: #b45309; }
  .score.high { color: #15803d; }
  .btn { display: inline-block; padding: .5rem 1rem; border-radius: 6px; border: none; cursor: pointer; font-size: .95rem; text-decoration: none; }
  .btn-local { background: #2563eb; color: #fff; }
  .btn-prod { background: #b91c1c; color: #fff; }
  .btn-reject { background: #e5e5e5; color: #333; }
  .pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; background: #eee; margin-right: .3rem; }
  section { margin: 1.25rem 0; padding: 1rem 1.25rem; background: #fff; border: 1px solid #e2e2e2; border-radius: 8px; }
  section h2 { margin-top: 0; font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: .4rem; }
  .subsection { margin: .75rem 0; }
  .subsection h3 { margin-bottom: .25rem; font-size: 1rem; }
  .faq-q { font-weight: 600; margin-bottom: .1rem; }
  .field { margin: .2rem 0; }
  .field-label { color: #666; font-size: .85rem; }
  .gallery { display: flex; gap: .75rem; flex-wrap: wrap; }
  .gallery-item { margin: 0; }
  .gallery-item img { width: 140px; height: 140px; object-fit: cover; border-radius: 6px; display: block; }
  .gallery-item figcaption { font-size: .75rem; color: #666; max-width: 140px; }
  figcaption { font-size: .8rem; color: #666; margin-top: .25rem; }
  .chip { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .8rem; margin: .15rem .3rem .15rem 0; }
  .chip-good { background: #dcfce7; color: #166534; }
  .chip-mixed { background: #fef3c7; color: #92400e; }
  .chip-bad { background: #fee2e2; color: #991b1b; }
  textarea { width: 100%; min-height: 4rem; font-family: inherit; }
  .empty { color: #888; padding: 2rem 0; text-align: center; }
</style>
</head>
<body>
${body}
</body>
</html>`;
}

function scoreClass(score) {
  const n = Number(score);
  if (isNaN(n)) return '';
  if (n < 5) return 'low';
  if (n < 7) return 'mid';
  return 'high';
}

const SENTIMENT_CLASS = { good: 'chip-good', mixed: 'chip-mixed', bad: 'chip-bad' };

function dataUri(base64, mime) {
  if (!base64) return null;
  return `data:${mime || 'image/png'};base64,${base64}`;
}

function field(label, value) {
  if (value === undefined || value === null || value === '') return '';
  return `<div class="field"><span class="field-label">${esc(label)}:</span> ${esc(value)}</div>`;
}

function renderReviewDetail(row, p) {
  const sc = scoreClass(p.score);

  const gallery = (p.gallery_image_urls || []).map(g => {
    const url = typeof g === 'string' ? g : g.url;
    const credit = typeof g === 'object' ? g.credit : '';
    return `<figure class="gallery-item">
      <img src="${esc(url)}" alt="${esc((typeof g === 'object' && g.alt) || '')}">
      ${credit ? `<figcaption>${esc(credit)}</figcaption>` : ''}
    </figure>`;
  }).join('');

  const heroBannerUri = dataUri(p.hero_banner_base64, p.hero_banner_mime);

  const aspectChips = (p.aspect_chips || []).map(c =>
    `<span class="chip ${SENTIMENT_CLASS[c.sentiment] || 'chip-good'}">${esc(c.label)}</span>`
  ).join('');

  const pros = (p.pros || []).map(x => `<li>${esc(x)}</li>`).join('');
  const cons = (p.cons || []).map(x => `<li>${esc(x)}</li>`).join('');

  const contentSections = (p.content_sections || []).map(s =>
    `<div class="subsection"><h3>${esc(s.heading)}</h3><p>${esc(s.content)}</p></div>`
  ).join('') || '<p class="meta">No content sections.</p>';

  const faq = (p.faq || []).map(f =>
    `<div class="subsection"><p class="faq-q">${esc(f.question)}</p><p>${esc(f.answer)}</p></div>`
  ).join('') || '<p class="meta">No FAQ entries.</p>';

  const alternatives = (p.alternatives || []).map(a =>
    `<li><strong>${esc(a.name)}</strong>${a.reason ? ` &mdash; ${esc(a.reason)}` : ''}</li>`
  ).join('');

  return `
    <p><a href="/">&larr; back to queue</a></p>
    <h1>${esc(p.title || row.product_name)}</h1>
    <div>
      <span class="pill">${esc(row.category || p.category || '')}</span>
      <span class="pill">${esc(row.brand || '')}</span>
      <span class="pill">${esc(p.product_key || '')}</span>
      ${p.image_source ? `<span class="pill">image: ${esc(p.image_source)}</span>` : ''}
    </div>

    <section>
      <h2>Scores</h2>
      <p><span class="score ${sc}">Score: ${esc(p.score ?? '—')}/10</span></p>
      ${field('Review count', p.review_count)}
      ${field('SEO score', p.seo_score)}
    </section>

    <section>
      <h2>SEO metadata</h2>
      ${field('SEO title', p.seo_title)}
      ${field('Meta description', p.meta_description)}
      ${field('Focus keyword', p.focus_keyword)}
    </section>

    <section>
      <h2>Media</h2>
      ${p.image_url ? `<figure>
        <img src="${esc(p.image_url)}" style="max-width:100%;border-radius:8px;" alt="${esc(p.image_alt || '')}">
        <figcaption>${esc(p.image_credit || '')}${p.image_alt ? ` &middot; alt: "${esc(p.image_alt)}"` : ''}</figcaption>
      </figure>` : '<p class="meta">No featured image.</p>'}
      ${gallery ? `<div class="gallery">${gallery}</div>` : ''}
      ${heroBannerUri ? `<figure><img src="${esc(heroBannerUri)}" style="max-width:100%;border-radius:8px;"><figcaption>AI-generated decorative hero banner</figcaption></figure>` : ''}
      ${p.video_url ? `<p><a href="${esc(p.video_url)}" target="_blank">Video: ${esc(p.video_url)}</a></p>` : ''}
    </section>

    <section>
      <h2>Summary</h2>
      <p>${esc(p.excerpt || '')}</p>
      ${aspectChips ? `<div>${aspectChips}</div>` : ''}
    </section>

    <section>
      <h2>Pros / Cons</h2>
      <ul>${pros || '<li class="meta">None listed</li>'}</ul>
      <ul>${cons || '<li class="meta">None listed</li>'}</ul>
    </section>

    <section>
      <h2>Review sections</h2>
      ${contentSections}
    </section>

    <section>
      <h2>Verdict</h2>
      <p>${esc(p.verdict || '')}</p>
      ${field('Best for', p.best_for)}
      ${field('Not best for', p.not_best_for)}
    </section>

    <section>
      <h2>FAQ</h2>
      ${faq}
    </section>

    <section>
      <h2>Alternatives</h2>
      ${alternatives ? `<ul>${alternatives}</ul>` : '<p class="meta">No alternatives listed.</p>'}
    </section>

    <section>
      <h2>Sources</h2>
      ${field('Sources', p.sources)}
      ${p.source_url ? `<p><a href="${esc(p.source_url)}" target="_blank">${esc(p.source_url)}</a></p>` : ''}
    </section>

    <section>
      <h2>Publish</h2>
      <form method="post" action="/review/${row.id}/publish" style="display:inline">
        <input type="hidden" name="target" value="local">
        <button class="btn btn-local" onclick="return confirm('Publish to LOCAL WordPress?')">Publish to Local</button>
      </form>
      <form method="post" action="/review/${row.id}/publish" style="display:inline">
        <input type="hidden" name="target" value="prod">
        <button class="btn btn-prod" onclick="return confirm('Publish to PRODUCTION (reviewgeekhub.com)? This goes live for real visitors.')">Publish to Production</button>
      </form>
      <form method="post" action="/review/${row.id}/reject" style="margin-top:.75rem">
        <textarea name="note" placeholder="Why is this being rejected? (goes into error_message for the record)"></textarea><br>
        <button class="btn btn-reject" style="margin-top:.5rem">Reject</button>
      </form>
    </section>
  `;
}

module.exports = { esc, layout, scoreClass, renderReviewDetail };

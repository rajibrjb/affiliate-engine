const test = require('node:test');
const assert = require('node:assert/strict');
const { mock } = require('node:test');
const { Pool } = require('pg');

// This app is a human approval gate that can trigger a real publish to
// production WordPress - these tests never hit a real DB or WordPress.
// `pg.Pool.prototype.query` is mocked before `server.js` is required, since
// the module creates its Pool (and reads env-driven config) at load time.
process.env.REVIEW_APP_PASSWORD = 'test-password';
delete process.env.LOCAL_WP_API_KEY;
delete process.env.PROD_WP_API_KEY;

const AWAITING_ROW = { id: 42, review_payload: { title: 'Test Product' } };

mock.method(Pool.prototype, 'query', async (sql) => {
  const text = typeof sql === 'string' ? sql : sql.text;
  if (/WHERE id = \$1 AND status = 'awaiting_review'/.test(text)) {
    return { rows: [AWAITING_ROW] };
  }
  return { rows: [] };
});

const app = require('../server');
const request = require('supertest');

test('rejects requests with no Authorization header', async () => {
  const res = await request(app).get('/');
  assert.equal(res.status, 401);
});

test('rejects requests with the wrong password', async () => {
  const res = await request(app).get('/').auth('anyone', 'wrong-password');
  assert.equal(res.status, 401);
});

test('accepts requests with the correct password', async () => {
  const res = await request(app).get('/').auth('anyone', 'test-password');
  assert.equal(res.status, 200);
});

test('publish route rejects an unknown target', async () => {
  const res = await request(app)
    .post('/review/42/publish')
    .auth('anyone', 'test-password')
    .type('form')
    .send({ target: 'bogus' });
  assert.equal(res.status, 400);
});

test('publish route 400s when the target has no API key configured', async () => {
  const res = await request(app)
    .post('/review/42/publish')
    .auth('anyone', 'test-password')
    .type('form')
    .send({ target: 'prod' });
  assert.equal(res.status, 400);
  assert.match(res.text, /No API key configured/);
});

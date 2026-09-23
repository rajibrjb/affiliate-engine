const test = require('node:test');
const assert = require('node:assert/strict');
const { esc, scoreClass, renderReviewDetail } = require('../render');

test('esc() escapes all HTML-significant characters', () => {
  assert.equal(esc(`<script>alert("x")</script> & 'y'`), '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; \'y\'');
});

test('esc() handles null/undefined/number without throwing', () => {
  assert.equal(esc(null), '');
  assert.equal(esc(undefined), '');
  assert.equal(esc(42), '42');
});

test('scoreClass() buckets numeric scores correctly', () => {
  assert.equal(scoreClass(3), 'low');
  assert.equal(scoreClass(4.9), 'low');
  assert.equal(scoreClass(5), 'mid');
  assert.equal(scoreClass(6.9), 'mid');
  assert.equal(scoreClass(7), 'high');
  assert.equal(scoreClass(10), 'high');
});

test('scoreClass() returns empty string for non-numeric input', () => {
  assert.equal(scoreClass(undefined), '');
  assert.equal(scoreClass('n/a'), '');
});

test('renderReviewDetail() escapes attacker-controlled pipeline output', () => {
  const row = { id: 1, category: 'Electronics', brand: '<img src=x onerror=alert(1)>' };
  const p = {
    title: `"><script>alert(1)</script>`,
    excerpt: 'Safe & sound',
    pros: ['<b>bold pro</b>'],
    cons: [],
    aspect_chips: [{ label: '<i>chip</i>', sentiment: 'good' }],
    content_sections: [{ heading: 'H1', content: '<script>x</script>' }],
    faq: [{ question: 'Q?', answer: 'A & B' }],
    alternatives: [{ name: 'Alt "1"', reason: 'because' }],
  };

  const html = renderReviewDetail(row, p);

  // Nothing attacker-controlled survives as a live tag/attribute.
  assert.ok(!html.includes('<script>alert(1)</script>'));
  assert.ok(!html.includes('<img src=x onerror=alert(1)>'));
  assert.ok(!html.includes('<b>bold pro</b>'));
  assert.ok(!html.includes('<i>chip</i>'));
  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
  assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
});

test('renderReviewDetail() does not throw on a minimal/mostly-empty payload', () => {
  const row = { id: 1, product_name: 'Fallback Name' };
  const html = renderReviewDetail(row, {});
  assert.ok(html.includes('Fallback Name'));
  assert.ok(html.includes('No featured image.'));
  assert.ok(html.includes('No content sections.'));
  assert.ok(html.includes('No FAQ entries.'));
  assert.ok(html.includes('No alternatives listed.'));
});

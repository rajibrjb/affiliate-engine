-- Adds a human-review gate before publishing.
--
-- Stage 3b used to publish straight to WordPress once QA passed. After a real
-- QA-scoring bug shipped a review with a broken score to a live post
-- unreviewed, publishing was changed to write the finished payload here
-- instead and wait for a human to approve it (via the review-app) and choose
-- which WordPress (local or prod) to send it to.
--
-- Apply with:
--   docker exec -i affiliate-n8n-db-1 psql -U n8n -d n8n < workflows/sql/003_review_drafts.sql

ALTER TABLE rgh.product_queue
  DROP CONSTRAINT IF EXISTS product_queue_status_check;

ALTER TABLE rgh.product_queue
  ADD CONSTRAINT product_queue_status_check
  CHECK (status IN ('pending','researching','writing','awaiting_review','published','failed','needs_review'));

ALTER TABLE rgh.product_queue
  ADD COLUMN IF NOT EXISTS review_payload JSONB,
  ADD COLUMN IF NOT EXISTS local_wp_post_id INTEGER,
  ADD COLUMN IF NOT EXISTS local_wp_link TEXT,
  ADD COLUMN IF NOT EXISTS prod_wp_post_id INTEGER,
  ADD COLUMN IF NOT EXISTS prod_wp_link TEXT;

COMMENT ON COLUMN rgh.product_queue.review_payload IS
  'Full publish_payload built by Stage 3b''s "Prepare Publish Payload" node - everything the WordPress ingest endpoint needs (title, content_sections, images, score, pros/cons, etc). Populated when status = awaiting_review, sent as-is to whichever WordPress the human picks in the review-app.';

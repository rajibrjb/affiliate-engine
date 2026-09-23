-- Product discovery queue for the n8n content pipeline.
-- Lives in the existing n8n-db Postgres instance, under its own schema so it
-- never collides with n8n's internal tables.
--
-- Apply with:
--   docker exec -i affiliate-n8n-db-1 psql -U n8n -d n8n < workflows/sql/001_product_queue.sql

CREATE SCHEMA IF NOT EXISTS rgh;

CREATE TABLE IF NOT EXISTS rgh.product_queue (
  id             BIGSERIAL PRIMARY KEY,
  product_name   TEXT NOT NULL,
  brand          TEXT,
  category       TEXT NOT NULL,
  country_code   CHAR(2) NOT NULL DEFAULT 'US',
  review_style   TEXT NOT NULL DEFAULT 'in_depth_review',
  product_key    TEXT NOT NULL UNIQUE,
  source_url     TEXT,
  status         TEXT NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','researching','writing','published','failed','needs_review')),
  discovery_meta JSONB NOT NULL DEFAULT '{}'::jsonb,
  wp_post_id     INTEGER,
  wp_link        TEXT,
  error_message  TEXT,
  retry_count    INTEGER NOT NULL DEFAULT 0,
  discovered_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  claimed_at     TIMESTAMPTZ,
  processed_at   TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_product_queue_status_discovered
  ON rgh.product_queue (status, discovered_at);

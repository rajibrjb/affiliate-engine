-- Adds manufacturer-image tracking to the product queue.
--
-- Stage 3a (discovery) scrapes the product's source_url for og:image /
-- twitter:image / JSON-LD Product.image tags and stores whatever it finds
-- here, tagged with where each image came from. Stage 3b (processing) prefers
-- these over the generic Openverse stock-photo fallback when publishing.
--
-- Manufacturer photos are typically copyrighted press images, not freely
-- licensed like the CC0/CC-BY Openverse catalog used elsewhere in this
-- pipeline - the `source` tag on each entry (and the mirrored
-- `_rgh_image_source` postmeta on the published WP post) exists specifically
-- so these can be found and reviewed/cleaned up later, not because the
-- licensing question has been resolved.
--
-- Apply with:
--   docker exec -i affiliate-n8n-db-1 psql -U n8n -d n8n < workflows/sql/002_product_queue_images.sql

ALTER TABLE rgh.product_queue
  ADD COLUMN IF NOT EXISTS product_images JSONB NOT NULL DEFAULT '[]'::jsonb;

COMMENT ON COLUMN rgh.product_queue.product_images IS
  'Array of {url, source, tag, is_primary}. source is "manufacturer_scrape" (scraped from source_url at discovery time) or absent/other for anything sourced later at process time (e.g. Openverse fallback).';

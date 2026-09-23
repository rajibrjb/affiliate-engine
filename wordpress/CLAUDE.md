# WordPress — Affiliate & Advertisement Site

## What this site is

A content site for **affiliate marketing and advertising** — deal posts, comparisons,
reviews, and coupon content that link out to merchants via affiliate links.

**This site does not sell anything directly.** There is no cart/checkout flow to
maintain. WooCommerce-style pages (Shop, Cart, Checkout, My account) came from the
theme demo and should be removed or left permanently unpublished — see Roadmap below.

## Follow-up: main navigation rebuilt, all dead links fixed

The primary nav (flagged as theme-demo cruft since Phase 1) is now real:
Home / Reviews / Categories (dropdown, 6 categories) / Best Rated / About /
Contact. Deleted the old 31-item "Main menu" (term_id 22) and rebuilt it via
`wp_update_nav_menu_item()`.

New pages created to back these destinations (real content, not lorem):
- **Reviews** (1316) — set as `page_for_posts`, native WP post index, no
  custom code needed.
- **Categories** (1317) — `[rgh_categories]` in a plain `.rgh-home` wrapper.
- **Best Rated** (1318) — new `[rgh_best_rated limit="N"]` shortcode
  (rgh-shortcodes.php), same review-row markup as Latest Breakdowns but
  sorted by `rehub_review_overall_score` across *all* posts, not just 4.
- **About, Contact, Affiliate Disclosure, Terms of Use** — new, real short
  copy matching the site's actual positioning/voice. Contact uses a
  placeholder `mailto:` (no real inbox or SMTP configured yet — noted
  in-page as a placeholder).
- **Privacy Policy** (page 3) — was WordPress's unedited default template
  ("Suggested text: ..." boilerplate, still in draft) — replaced with real
  minimal content and published.

**CSS refactor enabling this**: `.review-list`/`.review-row`/`.cat-row`/
`.cat-item`/`.side-card`/`.side-link`/`.content-grid`/`.col`/`.section-head`
etc. were only ever defined in the homepage's local `<style>` block, so a
standalone page like Categories or Best Rated had no way to use them. Copied
the reusable (non-homepage-specific) subset into `rgh-site.css` (loaded
site-wide) — homepage keeps its own copy too for now (accepted duplication,
not consolidated — real tech debt, see below).

**Found and fixed a real infra gap while wiring up pretty URLs for these
pages**: the site was serving plain `?page_id=123` URLs. `wp option update
permalink_structure` + `wp rewrite flush --hard` reported success but URLs
still 404'd — `.htaccess` existed but its `<IfModule mod_rewrite.c>...`
block was empty (just the BEGIN/END WordPress comment markers, no actual
`RewriteRule` lines), despite `AllowOverride All` and `mod_rewrite` both
being correctly configured in the container's Apache config
(`docker-php.conf`). WP-CLI silently failed to write the rules (permissions).
Fixed by writing the standard WordPress rewrite block to `.htaccess`
directly and reloading Apache (`apache2ctl graceful`). All new pages verified
`200` at their pretty URLs (`/reviews/`, `/best-rated/`, `/about/`, etc.).

**Every dead `href="#"` on the site fixed**, once real destinations existed:
homepage Quick Links (Best Rated/Latest Breakdowns/**Saved-Wishlist** —
swapped from "Compare Products", since Compare isn't actually wired up,
`rehub_option['compare_page']` is empty; didn't want to link to a broken
feature), "All categories →"/"All reviews →" section links, footer "For
customers" column (`widget_text[3]`), footer "Disclaimer"/"Mail Us" in the
copyright bar (`rehub_option['rehub_footer_text']`), and the sidebar "Quick
menu" widget that appears on every single review post (nav_menu term_id 23 —
was pointing to two old theme-demo pages, 314/484). One `href="#"` remains
site-wide: the theme's own native header login icon, which is intentionally
disabled (its own label literally says "Login / Register is disabled") — not
something to build out in this pass.

**Not done** (noted, not silently skipped): CSS duplication between
`rgh-site.css` and the homepage's local `<style>` block for the shared
components above — works fine now, but is real duplication worth
consolidating later. "Compare Products" is a real missing feature, not just
a missing link, if it's ever wanted. Contact page has no working form/SMTP
yet (matches the already-flagged Phase 7 item in the roadmap below).

## n8n content pipeline (`../workflows/`)

Two n8n workflows, meant to eventually feed real content into this site
(replacing the illustrative/placeholder posts from phase 3):

- **`stage2-research-agent.json`** — given just a product name, a local Ollama
  model autonomously searches the web (self-hosted SearXNG) and reads real
  pages to build a structured evidence set (facts/expert_findings/
  owner_sentiment) — no hand-typed research.
- **`stage1-review-writer.json`** — takes that evidence and writes the actual
  review via Ollama, with a code-based validator + a second Ollama QA pass
  before anything is considered "approved". Currently uses hand-pasted test
  evidence (a Weber grill) rather than being wired to Stage 2's output yet;
  "Save Review" is a placeholder, not yet wired to WordPress (that's Stage 4,
  not built).
- **Structure requirement (added + verified 2026-09-19):** every review must
  open with a complete `specifications` section (one entry per supplied
  fact — no cherry-picking) and close with an `owner_consensus` that blends
  *every* supplied owner-sentiment item, positive/negative/mixed alike, not
  just the flattering ones. Enforced twice: a deterministic check in
  **Validate Review** (cross-checks every fact id / sentiment id is actually
  cited — triggers the existing retry-with-feedback loop if not) and again by
  the **Ollama QA** pass (`specifications_complete`/`owner_consensus_complete`
  booleans, now required `true` in **IF QA Passed?**).
- **Verified against a real run**, not just code review: built an iPhone 17
  test evidence set and ran the actual pipeline against live Ollama
  (`gemma4:latest` writer, `qwen3.6:35b` QA — both confirmed running via
  `curl localhost:11434/api/tags`), executing the *real* node code extracted
  from the workflow JSON (not a reimplementation), including the retry loop.
  Two real bugs surfaced and were fixed from that run:
  - Pros/cons contained literal `**markdown bold**` syntax — our WordPress
    fields render these as plain text (`esc_html()`), so raw asterisks would've
    shown up on the live site. Added an explicit no-markdown rule to the
    writer's system prompt.
  - QA confidence came back as `100` (percent scale) against a threshold
    written for a `0–1` scale (`qa_min_confidence: 0.85`) — passed by luck,
    not correctness. Fixed by stating the `0–1` scale explicitly in the QA
    prompt *and* adding `minimum`/`maximum` bounds to the schema itself
    (belt-and-braces, not just prompt wording). Re-run confirmed
    `confidence: 0.95`. The retry loop itself proved out live too — attempt 1
    genuinely dropped one sentiment id from `owner_consensus`, validation
    caught it, attempt 2 fixed it automatically.
  - If this workflow is already imported into a running n8n instance,
    editing the JSON file on disk does **not** auto-update it there — it
    needs re-importing (or manually pasting the updated node code in).

## Stage 0 — Master Pipeline: discover → research → write → QA → photo → publish (added 2026-09-19)

`workflows/stage0-master-pipeline.json` is the actual end-to-end pipeline the
user asked for: give it a **category**, it finds **N unique real products**,
and for each one runs the full Stage 2 research+writer+QA chain, sources a
licensed product photo, and publishes an approved review straight into
WordPress. A product that fails any trust gate is **skipped, not published**
— the loop continues to the next product rather than fabricating anything.

- **Trigger**: a Webhook node (`POST /webhook/rgh-run-pipeline` once the
  workflow is active), plus a Manual Trigger for testing in the n8n UI.
  Manual-execution via n8n's internal `/rest/workflows/:id/run` REST endpoint
  turned out to be fragile/version-specific (`executeManually` payload shape
  errors) — the webhook is the reliable way to trigger this from outside the
  UI (cron, curl, another workflow).
- **Config node**: `category`, `product_count` (default 10), Ollama
  models/URL, and `wp_api_url`/`wp_api_key` for the publish step.
- **Discovery** (new): `Search Category Candidates` runs 4 SearXNG query
  angles for the category and pools unique result URLs → `Prepare Discovery
  Prompt`/`Ollama Discover Products` asks Ollama to shortlist up to
  `product_count` distinct real products, **each with a source_url that must
  be copied verbatim from the real search results** (never invented) →
  `Parse Discovery JSON` dedupes by normalized product name and drops any
  product whose URL doesn't actually match a real search result, then
  explodes the list into one n8n item per product.
- **Loop**: `Loop Products` (SplitInBatches, batch size 1) feeds one product
  at a time into the *unchanged* Stage 2 research/writer/QA node chain
  (`Search All Categories` → ... → `Save Review`). All three "Needs Human
  Review" dead-ends (research untrustworthy / validation failed after
  retries / QA failed) now loop back into `Loop Products` instead of
  terminating the whole run — one bad product no longer blocks the rest.
- **Research-trust retry (new, needed after a real bug)**: the first real
  run surfaced a genuine gap — `Choose Sources` picked the *same* domain for
  both the `specs` and `independent` categories (Ollama sourced manufacturer
  specs from a third-party review site and then cited that same site as the
  "independent" finding), which correctly failed `Validate Research Trust`'s
  same-domain check but had **no retry path**, unlike the writer stage — one
  bad source pick permanently killed that product. Added `IF Research
  Retries Left?` / `Retry Choose Sources` (one retry, feedback-driven, same
  pattern as the existing writer retry loop). This needed `_researchRetryCount`
  threaded through `Parse Chosen URLs` and `Parse Research JSON`, both of
  which rebuild their return object from scratch (spreading `...item` would
  have carried it automatically, but these two don't) and would otherwise
  silently drop it every retry.
- **Photo sourcing**: `Source Product Photo` queries the public **Openverse**
  API (`api.openverse.org`, no key required) for a commercially-licensed
  image matching the product, with a `{creator} · {license} · via Openverse`
  credit string. A missing photo is not fatal — the post still publishes
  without a featured image rather than blocking the pipeline.
- **Publish**: `Prepare Publish Payload` maps the approved review (Stage 2's
  schema — note it's a *different* shape from Stage 1's hand-tested schema:
  plain `owner_consensus` string, no `specifications` array) plus the photo
  into the ingest endpoint's payload (see below), then `Publish To
  WordPress` POSTs it with the `X-RGH-Api-Key` header.
- **WordPress ingest endpoint** (new): `wp-content/mu-plugins/rgh-ingest-api.php`
  — lives in the WordPress container's volume, **not** the host repo (the
  `wordpress_data` docker volume isn't bind-mounted; `docker cp` it in again
  after any container/volume recreation). Registers
  `POST /wp-json/rgh/v1/publish-review`, auth via a shared-secret
  `X-RGH-Api-Key` header (the key `RGH_INGEST_API_KEY` is a literal constant
  in that file — rotate it there if it ever leaks, and update the matching
  `wp_api_key` value in the Config node of any workflow that calls it).
  Dedupes on `product_key` (a slugified `brand-product` string) via a
  `_rgh_product_key` postmeta lookup — re-running discovery for a product
  already on the site **updates** that post instead of creating a duplicate,
  which is what makes "top N unique products" hold up across repeated runs.
  Maps payload fields onto the exact same `_rgh_*` postmeta contract the
  "Product Review" meta box uses (see below) so pipeline-published posts and
  hand-written ones render identically, creates/matches the WP category by
  name, and sideloads `image_url` as the real featured image via
  `media_handle_sideload()` (verified working against a real external image
  URL, not just a mocked call).
- **Verified against real live runs, not just imported and assumed correct**
  — and it took several real runs to actually get a post published, each one
  surfacing a genuine bug that code review alone wouldn't have caught:
  - The ingest endpoint was curl-tested directly first (before n8n was even
    involved): create, idempotent update (same `product_key` twice → same
    `post_id`, not a duplicate), category auto-creation, and real image
    sideload all confirmed against the live site.
  - **Run 1** (`product_count: 2`, "wireless earbuds"): both products dead-
    ended at "Needs Human Review - Research" — no retry existed for a
    research-trust failure (only the writer stage had one), so a single bad
    `Choose Sources` pick permanently killed the product. Root-caused via the
    execution's decoded run data (n8n stores it in `flatted` format, not
    plain JSON — see below), not guesswork. Fixed by adding the same
    retry-with-feedback pattern the writer stage already had.
  - **Run 2** (retry loop now firing): still zero posts, same error message,
    even for a product whose sourcing was genuinely fine (manufacturer spec
    page + a real independent review site, different domains). Isolated with
    a throwaway one-node test workflow
    (`{hasURL: typeof URL, hostname: new URL(...).hostname}` behind a
    webhook) that came back `{"error":"URL is not defined"}` — **n8n's Code
    node sandbox has no global `URL` constructor.** `hostnameOf()`'s
    `try/catch` was silently swallowing that on every call, so the
    "genuinely independent source" check always failed regardless of actual
    source quality. Fixed by rewriting `hostnameOf()` with a regex instead
    of `new URL()` (both in this workflow and in the underlying
    `stage2-research-agent.json`, since the bug lives there).
  - **Run 3** (after the URL fix, first real publish): a post landed, but
    with no featured image. `Source Product Photo`'s Openverse query was the
    literal product name (e.g. "Apple AirPods Pro 3") — confirmed directly
    against the live Openverse API that this returns **zero** results for
    an exact recent SKU (Openverse indexes CC stock/wiki photography, not
    product press photos), while a broader query like "AirPods Pro" returns
    137. Fixed with a progressive fallback: exact name → name alone →
    brand + category → category, taking the first query that returns a hit.
  - **Run 4** (same run, quality check on the published post): pros/cons
    text had a literal `[EXP-001]`-style citation id leaking into
    reader-facing prose. Added an explicit prompt rule against it, but a
    sibling product in the very same batch still leaked one anyway
    (`"...muted mids. (SENT-002)"`) — a local 8B/35B model does not follow
    that instruction with 100% reliability. Prompting alone wasn't a
    trustworthy enough guarantee for something this visible on a live page,
    so added a **deterministic** regex strip of any `(FACT-N)`/`[EXP-N]`/
    `(SENT-N; FACT-N)`-style group in `Prepare Publish Payload`, applied to
    every reader-facing field (title, excerpt, pros, cons, verdict,
    best_for, not_best_for, section content, FAQ, alternatives) as a safety
    net regardless of what the model actually produced.
  - **Run 5**: two real products — Sony WF-1000XM6 and Apple AirPods Pro 3 —
    published clean: real sideloaded featured images, zero leaked citation
    ids, the sitewide affiliate-disclosure line rendering, consensus box/
    aspect chips/pros/cons/verdict all correct. Left live on the site as the
    pipeline's first genuine output (not deleted as test churn).
  - To decode an n8n execution's data for debugging: `GET
    /rest/executions/:id?includeData=true` returns `data.data` as a
    `flatted`-encoded string, not plain JSON — `JSON.parse()` on it directly
    is useless. Decode with the actual `flatted` npm package (`parse()` from
    `require('flatted')`), not a hand-rolled reimplementation — its reference
    scheme (real strings vs index-pointer strings) is easy to get subtly
    wrong from memory.
  - Deployed and left **active** at the production default `product_count:
    10` via the webhook trigger — running it for real 10-product batches
    (vs. the `product_count: 2` used for these debugging runs) was not done
    in this session for time/cost reasons, but every stage of the chain has
    now been proven against real output, not just imported and assumed to
    work.
- Requires WordPress reachable at `http://wordpress` from inside the n8n
  container (both are on the `app-network` docker-compose network) — not
  `localhost:8080`, which only resolves on the host.
- **Known remaining rough edge**: aspect-chip labels for pipeline-published
  posts are derived by truncating the first ~4 words of a pros/cons sentence
  (`"Meaningful performance upgrades, specifically — good"`) rather than a
  genuinely short label — readable but visibly less polished than the
  hand-written seeded posts' chips. Would need either a dedicated
  short-label extraction step or a schema field for it; not done here.

### Follow-up (2026-09-19): hero photo gallery, official video embed, disclosure repositioned

User feedback on a live published post: no real "hero" photo presence (just
the theme's small 225x150 title-area thumbnail), no video even though
official product videos exist, and the affiliate disclosure was the literal
first line of the post — jarring, reads as an ad before any content.

- **Disclosure moved to the bottom** of the auto-rendered box (right after
  the verdict, before `</div>`), not the first thing rendered — still fully
  visible, has its own quiet styling now (`rgh-affiliate-notice`: small,
  muted, hairline divider above) instead of being unstyled like before.
- **Hero photo gallery** (`_rgh_gallery` postmeta, an array of attachment
  ids): renders at the very top of the box as one large photo + two smaller
  stacked photos (`.rgh-hero-gallery`, CSS grid `2fr 1fr` / two rows) —
  genuinely reads as a hero treatment, not a thumbnail strip.
- **Official video embed** (`_rgh_video_url` postmeta, any full YouTube URL —
  watch/shorts/youtu.be all parsed by `rgh_youtube_id()`): renders as a
  responsive 16:9 `youtube-nocookie.com` iframe under a "WATCH IT IN ACTION"
  label, right after the consensus score.
- **n8n pipeline updated to source both automatically, no manual step**:
  `Source Product Photo` now pulls up to 4 images from the *same* successful
  Openverse query tier (1 featured + up to 3 gallery) instead of just one —
  deliberately not mixing tiers, so an exact-match photo never ends up next
  to an unrelated category-filler one in the same gallery. New `Find Product
  Video` node searches SearXNG for `"{brand} {name} official video"
  site:youtube.com` and only uses a URL that's actually a `youtube.com/watch`
  result from real search output (prefers a title containing both the brand
  name and the word "official") — never fabricates a video if none is found.
- **Ingest endpoint extended** (`rgh-ingest-api.php`) to accept
  `gallery_image_urls[{url,credit}]` (sideloads each, stores the resulting
  attachment ids as `_rgh_gallery`) and `video_url` (stored directly as
  `_rgh_video_url`).
- **Backfilled both pre-existing pipeline posts** (Sony WF-1000XM6, Apple
  AirPods Pro 3) with real galleries + real official videos so they match
  new output — done via a one-off PHP script directly setting postmeta and
  sideloading images, not by re-running the ingest endpoint's update path
  (which would have overwritten their existing good `post_content` by
  rebuilding it from an incomplete payload). One backfilled photo (a person's
  portrait, mismatched to "true wireless earbuds") was caught by actually
  viewing the downloaded image before publishing, not just trusting
  Openverse's relevance ranking, and swapped for a real product shot —
  matches the "Openverse relevance is mediocre" gotcha from the phase-3
  photo pass; worth remembering for any future manual photo picks too.
- **Verified against a real live run** with the new code (category
  "mechanical keyboards", `product_count: 2`): both products published with
  a 3-photo gallery, a real official-style YouTube video, and a correctly
  bottom-positioned disclosure, with zero manual intervention — confirmed
  visually via Playwright screenshots, not just by checking postmeta exists.
- **On pipeline runtime** (came up when the wireless-earbuds test runs took
  10+ minutes for 2 products): profiled it for real using Ollama's own
  per-call `total_duration` field rather than guessing — for a full 2-product
  run, summed Ollama call time was 11m22s against an 11m57s total wall clock,
  i.e. ~95% of the runtime is waiting on local LLM inference, not n8n or
  network overhead. Each product makes a minimum of 4 sequential Ollama calls
  (choose-sources → extract-research → write → QA, more with retries), and
  the pipeline swaps between two different local models per product
  (`qwen3.6:35b` for research/QA, `gemma4:latest` for writing) — some
  per-call overhead didn't fully resolve into prompt-eval/generation/model-load
  time individually, consistent with model-swap and/or reasoning-token cost
  on top of raw generation, though the exact split wasn't nailed down further.
  Practical implication: the default `product_count: 10` run is realistically
  a 45–75 minute job — treat it as fire-and-forget via the webhook, not
  something to run synchronously and wait on.

### Follow-up (2026-09-19): "4.5 score from 0 reviews" read as broken, not honest

User feedback on the live Keychron post: the Consensus Score box said "from
0 reviews" right next to a real 4.5 rating - technically honest (`_rgh_review_count`
only tallies distinct owner-sentiment items found in research, and this
product's research had zero of those, just manufacturer specs + expert
testing) but reads as contradictory/broken to a visitor, not as intended
transparency.

- Fixed in `rgh-review-feature.php`'s consensus-callout: when count is 0,
  the subtitle now reads "based on expert testing & official specs" instead
  of "from 0 reviews" - still accurate about what actually backs the score,
  just not phrased as a review count that isn't one. Count > 0 is unchanged
  ("from N reviews").
- Same underlying issue existed in three places in `rgh-shortcodes.php`
  (Featured card, Latest Breakdowns, Best Rated rows) that render a star
  rating with a `(N reviews)` count pulled from the same meta - wrapped each
  in `if ( $count > 0 )` so a zero-review post's listing card just shows the
  star rating with no parenthetical, rather than `(0 reviews)`.
- Verified on the live Keychron post (screenshot) and confirmed the
  Sony/AirPods posts, which do have real owner-sentiment counts (4 and 3),
  still render "from N reviews" unchanged - the fix is conditional, not a
  blanket removal of the review-count feature.

## Stage 3 — Automated discovery queue + processor (added 2026-09-20)

Stage 0 (above) discovers and publishes in one single run/execution — fine for a
manual "run it now" trigger, but not what "fully automatic" means: it has no memory
between runs, so there's no way to say "add 10 new products a day" and separately
"keep grinding through whatever's queued" on a different cadence. Stage 3 splits
that into two independent, schedulable n8n workflows that share a persistent
Postgres queue table, so discovery and writing can run on different clocks (or be
triggered independently) instead of being locked together in one execution.

- **`workflows/sql/001_product_queue.sql`** — creates `rgh.product_queue` in the
  **existing n8n-db Postgres instance** (own schema, so it never collides with
  n8n's internal tables; no new service needed). Columns: `product_name`, `brand`,
  `category`, `country_code` (rotates across US/GB/CA/AU), `review_style` (rotates
  across `in_depth_review`/`buyers_guide`/`comparison`/`budget_pick`),
  `product_key` (unique, `brand-product-country` slug — this is what makes "10
  unique products" hold across repeated daily runs), `source_url`, `status`
  (`pending → researching → published`, or `needs_review`/`failed`),
  `discovery_meta` (jsonb), `wp_post_id`/`wp_link`, `error_message`, `retry_count`,
  `discovered_at`/`claimed_at`/`processed_at`. Apply with `docker exec -i
  affiliate-n8n-db-1 psql -U n8n -d n8n < workflows/sql/001_product_queue.sql`.
- **n8n credential**: a Postgres credential named "RGH Content DB" (id
  `rgh-content-db`) pointing at host `n8n-db` (the docker-compose service name),
  database `n8n`, using the same `N8N_DB_USER`/`N8N_DB_PASSWORD` from `.env` — both
  Stage 3 workflows reference this credential by id, so it must exist in n8n before
  they'll run (CLI: `n8n import:credentials`, or add it once by hand in the editor).
- **`workflows/stage3a-discover-and-queue.json`** — Daily Schedule (06:00) +
  Webhook + Manual triggers. Rotates through a 14-category list by day-of-year
  (override via webhook body `{category, product_count}`), runs the same
  SearXNG+Ollama discovery as Stage 0's discovery step but explicitly asks for the
  most *popular*/best-selling products, excludes anything whose normalized
  brand+name already exists anywhere in `rgh.product_queue` (queried fresh every
  run — this is what keeps "10 unique products/day" true over time, not just
  within a single run), assigns `country_code`/`review_style` by rotation, and
  `INSERT ... ON CONFLICT (product_key) DO NOTHING`s each one. A "Discovery
  Summary" node reports attempted/inserted/skipped-as-duplicate counts.
- **`workflows/stage3b-process-queue.json`** — Schedule (every 30 min) + Webhook +
  Manual triggers. Atomically claims up to `batch_size` (default 5) `pending` rows
  (`UPDATE ... FOR UPDATE SKIP LOCKED`), first reclaiming any row stuck in
  `researching`/`writing` for more than `stale_minutes` (default 30) back to
  `pending` — this is the self-healing path for a crashed/killed execution, and it
  was genuinely exercised during testing (see below), not just theorized. Then
  feeds claimed rows **one at a time** (`SplitInBatches`, batch size 1 — strictly
  sequential, never parallel) through the *unchanged* Stage 0 research → write → QA
  → SEO → photo → publish node chain, with `country_code`/`review_style` threaded
  into the writer's system prompt (audience/spelling + structural framing — never
  used to invent facts, same evidence-grounding rules apply regardless of style).
  Every terminal outcome updates the row instead of dead-ending: research
  untrustworthy / validation failed / QA failed (each after their existing retry
  loop) → `needs_review` + a real error message, and the loop continues to the next
  row; a successful publish → `published` + `wp_post_id`/`wp_link`.
- **Both workflows are `n8n update:workflow --id=... --active=true` active** (the
  installed n8n build needs a container restart after activating for schedule/
  webhook registration to take effect — `docker restart affiliate-n8n-1`).
- **Manual/webhook triggering is unreliable on this n8n build** (`Version: 2.29.10`
  — a much newer/customized fork than typical OSS n8n, with a draft/publish
  workflow-versioning system not documented anywhere I could find): webhooks
  registered via CLI import land in `webhook_entity` under a mangled path
  (`<workflowId>/webhook trigger/<path>` instead of just `<path>`) and 404 even
  when hit at that exact registered path — Stage 0's original webhook (registered
  years earlier under this same instance, before whatever changed) still works
  fine, so this looks like an import/registration bug specific to this fork rather
  than something wrong in the workflow JSON. **Reliable way to run either workflow
  on demand**: open it in the n8n editor UI (http://localhost:5678) and click
  Execute, or use a disposable one-off container against the same Postgres (see
  below) — not the production webhook URL, until/unless that's debugged.
  ```
  docker run --rm --network affiliate_app-network \
    -e DB_TYPE=postgresdb -e DB_POSTGRESDB_HOST=n8n-db -e DB_POSTGRESDB_PORT=5432 \
    -e DB_POSTGRESDB_DATABASE=n8n -e DB_POSTGRESDB_USER=n8n -e DB_POSTGRESDB_PASSWORD=<from .env> \
    -e N8N_ENCRYPTION_KEY=<cat /home/node/.n8n/config inside affiliate-n8n-1> -e GENERIC_TIMEZONE=UTC \
    docker.n8n.io/n8nio/n8n:latest execute --id=stage3adiscoverqueue01
  ```
- **Verified against real live runs** (not just imported and assumed correct — this
  took 4 real attempts against live SearXNG/Ollama/Postgres/WordPress, each
  surfacing a genuine bug fixed before moving on, not hypothesized in review):
  - A `Config` node referenced a variable that was never declared
    (`ReferenceError: review_styles is not defined`) — real typo, only caught by
    actually executing the node.
  - **Silent zero-item starvation**: `n8n-nodes-base.postgres` in `executeQuery`
    mode emits zero output items when a query matches zero rows, and n8n does not
    run any downstream node with zero input items — so an empty `product_queue`
    table (first run) or zero stale rows to reclaim (the common case) silently
    halted the *entire* workflow one node in, with a "success" status and no error.
    Fixed with `alwaysOutputData: true` on every Postgres node whose row count can
    legitimately be zero (`Get Existing Product Keys`, `Reclaim Stale Rows`,
    `Claim Batch`, `Insert Into Queue`), with downstream code filtering the forced
    placeholder item back out by checking for an `id` field before treating it as
    real data.
  - **`{"type": "boolean", "operation": "notEqual"}` is not a valid filter operator
    on this build** (`Unknown filter parameter operator "boolean:notEqual"`) —
    silently mis-routed items to the wrong IF branch rather than erroring loudly.
    Rewrote both hand-written IF conditions (`IF Products Found?`, `IF Has Rows?`)
    to use the same `{"operation": "true", "singleValue": true}` idiom Stage 0
    already uses everywhere else, with the upstream Code node emitting an explicit
    positive boolean (`_hasProducts`, `_hasRows`) rather than negating an error
    field at the IF layer.
  - **Pre-existing gap in Stage 0's own reused chain** (present there too, just
    never exercised): `Parse QA JSON` rebuilds its return object from scratch and
    drops `ollama_base_url`/`qa_model`/`writer_model` — fields the *downstream* SEO
    chain (`Ollama SEO Score`, `Ollama SEO Fix`) needs. Surfaced as `Invalid URL:
    /api/chat` once QA passed and execution reached `Validate SEO`. Fixed in
    Stage 3b's copy of the node by threading those three fields through; **Stage 0
    has this same latent bug and hasn't been patched**, since it wasn't in scope
    here — flagging it for whoever next touches Stage 0's SEO chain.
  - **Comma-corrupted SQL parameters**: n8n's Postgres `queryReplacement` splits a
    resolvable's evaluated string on commas before binding it as a query parameter
    (unless the string happens to parse as JSON) — a real validation-failure
    message ("...165 chars, must be roughly 100-160") got split mid-sentence,
    shifting the next bound parameter (`queue_id`) out of position and erroring
    `invalid input syntax for type bigint`. Fixed by stripping commas
    (`.replace(/,/g, ';')`) from all free-text diagnostic messages written to
    `error_message`.
  - **Local Ollama needs more than n8n's 5-minute HTTP default**: a real
    `Extract Research JSON` call against `qwen3.6:35b` legitimately took 5+ minutes
    (see timing below) and once hit n8n's default timeout mid-inference with a
    healthy Ollama server on the other end. Raised to 15 minutes (`timeout: 900000`)
    on every Ollama-calling HTTP node in Stage 3b plus Stage 3a's discovery call.
  - **Final clean run** (2 real queued products — Anker C1000 Gen 2, Jackery 2000
    v2 — found by Stage 3a's own discovery, not hand-picked): Anker published for
    real (`wp_post_id: 1393`, verified 200 + correct title/status via the WP REST
    API) in one pass; Jackery hit `needs_review` after exhausting the SEO-fix retry
    budget (title/description still over length) — the *system* working as
    intended (skip and continue, don't publish something that didn't pass), not a
    bug. Per-node timing from that run, for anyone tuning this later: `Extract
    Research JSON` ~310s, `Choose Sources` ~130s, `Ollama Writer` ~60-80s per
    attempt — the research-extraction call against the 35B model dominates total
    time per product, not workflow overhead.

### Follow-up (2026-09-21): manufacturer product images, sourced at discovery time

Featured/gallery photos were entirely Openverse (generic CC-licensed stock/wiki
media) — not an actual picture of the product. Added a step that scrapes the
product's own `source_url` (already verified against real search results by
discovery) for its `og:image`/`twitter:image`/JSON-LD `Product.image` tags —
the same metadata manufacturer sites set for their own link-preview cards — as
a deterministic, no-extra-API-key way to get precise product photography.

- **New node, Stage 3a**: `Source Manufacturer Images` (between `IF Products
  Found?` and `Insert Into Queue`) fetches `source_url`'s HTML and extracts up
  to 5 candidate image URLs. Not fatal on failure — an unreachable/blocking
  page just leaves the list empty. Relative URLs are resolved by hand (regex),
  not `new URL()` — that constructor doesn't exist in n8n's Code node sandbox,
  a real bug hit earlier in this same pipeline (see `hostnameOf()` above).
- **New column**: `rgh.product_queue.product_images` (jsonb array of
  `{url, source, tag, is_primary}`, migration
  `workflows/sql/002_product_queue_images.sql`). Threaded through Stage 3b's
  `Claim Batch` → `Prepare Queue Item` so it survives the long research/write/
  QA chain the same way `queue_id` already does (referenced by node name,
  not passed through every intermediate node).
- **Stage 3b's `Source Product Photo`** now checks these first and, if
  present, uses them directly (`image_source: 'manufacturer'`) — Openverse
  (`image_source: 'openverse'`) only runs as a fallback when discovery found
  nothing.
- **Deliberately unresolved licensing question, tracked not ignored**:
  manufacturer press photos are typically copyrighted, unlike Openverse's
  CC0/CC-BY catalog. Rather than block on that decision, every image and every
  published post now carries where its photo came from —
  `product_images[].source` in the queue row, and `_rgh_image_source` postmeta
  (`'manufacturer'`/`'openverse'`/`'none'`) on the WP post via the ingest
  endpoint — specifically so a future pass can query for
  `_rgh_image_source = 'manufacturer'` and review/replace/license those
  before any real launch, rather than silently mixing licensed and unlicensed
  photos with no way to tell them apart later.
- **Not done**: no vision-model verification that a scraped image is actually
  a clean product photo (vs. a lifestyle banner, a logo, or an unrelated
  og:image) — same "trust but don't over-engineer yet" tradeoff as the
  original Openverse relevance filtering.

### Follow-up (2026-09-21): live-tested, found and fixed a real Openverse false positive

Ran the feature above end-to-end against live SearXNG/DeepSeek/Openverse/Postgres/
WordPress (not just imported and assumed correct) — two real bugs surfaced and were
fixed, plus one real operational incident.

- **Bug 1 — discovery's `source_url` isn't reliably the manufacturer's own site**:
  a live run for "Sony WH-1000XM6" had `source_url = rtings.com` (a third-party
  "best noise-cancelling headphones" roundup, not Sony's site) — the scraper
  correctly pulled its `og:image`, but that's rtings' own listicle graphic, not a
  Sony product photo. Fixed by adding `looksLikeBrandDomain()` to `Source
  Manufacturer Images`: scraped images are only kept when the source page's
  hostname plausibly belongs to the brand. Confirmed live: re-running discovery on
  the same product now correctly returns an empty `product_images` instead of a
  mislabeled photo, and Stage 3b correctly falls through to Openverse.
- **Bug 2 — the pre-existing Openverse relevance check (from the phase-3 photo
  pass) is exploitable when the brand name is also a camera manufacturer**: with
  Bug 1 fixed, Stage 3b's Openverse fallback fired for real and published a
  totally unrelated blurry street/car-mirror photo as the Sony WH-1000XM6 review's
  hero image — caught by actually viewing the downloaded image, not by trusting
  the pipeline's own success status (same discipline as the phase-3 "Openverse
  relevance is mediocre" note). Root-caused via Flickr's oEmbed API (Openverse's
  own search proved non-deterministic between identical requests, so its search
  results couldn't be used to reliably reproduce the failure): the photo was
  titled **"Sony RX1 sample - forgot to switch focus ring..."** — a Sony *camera*
  sample shot. The original `isRelevant()` only required the brand name to appear
  in title/tags, which this title trivially satisfies. First attempted fix (require
  a product-model token match too) still let a second, similarly-titled Sony-camera
  photo through, because the brand+category tier (`Sony noise cancelling
  headphones`) still checked brand alone. Real fix: that tier now requires **both**
  a brand match *and* a category-word match, closing the gap. Verified with a
  deterministic adversarial unit test (three mock candidates — the exact Sony RX1
  false positive, an unrelated photo carrying a spurious "sony" tag, and a
  genuinely relevant Sony-headphone photo — all returned in the same mixed result
  set) rather than relying on Openverse's own unstable live ranking to happen to
  reproduce the bug again.
- **Operational incident, no bad content escaped**: reactivating both Stage 3a/3b
  workflows to restore their normal schedule (standard post-test cleanup) also
  restarted their live cron triggers *before* the image-relevance bug above was
  found — the real `every 30 min` schedule autonomously claimed 3 genuine queue
  rows in the background using the still-buggy code. Caught by checking
  `execution_entity` and `product_queue.wp_post_id` directly: all of those runs
  errored out before reaching publish, so nothing bad went live, but it was a real
  near-miss. Both workflows were immediately deactivated and left **inactive** —
  they need to be explicitly reactivated (`n8n update:workflow --id=... --active=true`
  + `docker restart affiliate-n8n-1`, per the activation gotcha documented above)
  before either will run on its own schedule again.

### Follow-up (2026-09-22): editorial-site image tracking + AI-generated decorative banner

Two more image-sourcing additions, prompted by user feedback comparing a Gizmodo
review's structure/photo credits against this pipeline's output.

- **Editorial-site image scraping, tracked like the manufacturer scrape**: Stage
  3b's `Fetch Chosen Pages` already fetches the HTML of whatever "specs"/
  "independent" source URLs `Choose Sources` picked, to extract research text —
  now it also pulls `og:image`/`twitter:image` off those SAME fetches (no extra
  HTTP round trip) and tags each by domain: `manufacturer_scrape` if the source
  page's hostname belongs to the brand, `editorial_scrape` otherwise (e.g. a real
  photo off rtings/theverge/gizmodo's own review page). `Source Product Photo`
  now checks these as a second-priority tier — after Stage 3a's discovery-time
  manufacturer scrape, before Openverse — sorted manufacturer-first. Explicitly
  **not** treated as equivalent to Openverse: `editorial_scrape` is someone else's
  copyrighted editorial photography (a bigger licensing risk than a brand's own
  press photo, let alone CC-licensed stock), so it's tagged with its own
  `image_source` value and an "(editorial - unverified license)" credit string —
  same "build the mechanism, mark provenance for later cleanup" approach as the
  manufacturer scrape, not a claim the licensing question is resolved. Verified
  with a unit test (mock manufacturer + editorial HTML fixtures) confirming
  correct domain tagging and manufacturer-first sort — not yet exercised in a full
  live pipeline run.
- **AI-generated decorative hero banner (Nano Banana / Gemini 2.5 Flash Image)**:
  new `Generate Hero Banner` node (after `Find Product Video`, before `Prepare
  Publish Payload`) calls Gemini's image API with a deliberately abstract prompt —
  explicit "no specific real device, no brand logo, no readable text" instruction,
  since generative models can't reliably reproduce an exact product's design
  (same lesson as the Openverse relevance work: an inaccurate fake render of a
  real device would undermine reader trust more than no banner at all). This is
  the one image on the post with **no licensing question at all** — we generate
  it, we own it — unlike every scraped/Openverse photo elsewhere. Renders above
  the existing real hero photo gallery (`rgh-hero-banner` in
  `rgh-review-feature.php`/`rgh-site.css`), never replacing it. New
  `rgh_ingest_sideload_base64_image()` in `rgh-ingest-api.php` handles this
  specifically because Gemini returns image bytes directly (base64), not a URL —
  can't reuse the existing `download_url()`-based sideload path. Stored as
  `_rgh_hero_banner_id` postmeta, gated the same way the featured image is
  (`$created || not already set`) so an idempotent re-publish doesn't re-call
  Gemini and re-upload a new banner every time.
- **Blocked on billing, not code**: live-tested against the real `GEMINI_API_KEY`
  in `.env` — the exact request shape works and the model resolves
  (`gemini-2.5-flash-image` → `gemini-2.5-flash-preview-image` internally), but
  returned `RESOURCE_EXHAUSTED` / `limit: 0` for the free tier. This is a
  billing-plan limitation on the Google Cloud project behind that key, not a rate
  limit that clears on retry — confirmed DeepSeek has no image-generation API at
  all (checked its docs directly) before ruling it out as a substitute. Per user
  decision, left wired and code-complete but **unverified end-to-end** until
  billing is enabled on that project; failure is non-fatal by design (`try/catch`,
  `hero_banner_base64: null`), so a post publishes fine with just the real photo
  gallery and no banner until then.
- **Deployed but not activated**: `rgh-ingest-api.php`, `rgh-review-feature.php`,
  and `rgh-site.css` were `docker cp`'d into their respective containers (none of
  these paths are bind-mounted — same "doesn't survive a volume reset" caveat as
  every other mu-plugin/theme-file note above) and `stage3b-process-queue.json`
  re-imported into n8n. Both Stage 3a/3b workflows remain **inactive** (left that
  way after the cron incident above) — reactivate deliberately, not as a
  side-effect of an import/update command.

## Stack & local environment

- Runs via `docker-compose.yml` in the repo root, alongside unrelated services
  (n8n, SearXNG) — only the `wordpress` and `wordpress-db` services matter here.
- WordPress: `affiliate-wordpress-1` (image `wordpress:latest`), served at
  **http://localhost:8080**.
- DB: `affiliate-wordpress-db-1` (MySQL 8.0). Credentials in `../.env`
  (`WORDPRESS_DB_*`). Query it with:
  `docker exec affiliate-wordpress-db-1 mysql -u wordpress -pchangeme wordpress -e "..."`
- **WP-CLI is not baked into the image.** It was installed manually at
  `/usr/local/bin/wp` inside the running container — this does **not** survive a
  container recreation (`docker compose up --force-recreate` / volume reset). If `wp`
  is missing, reinstall:
  `docker exec affiliate-wordpress-1 curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && docker exec affiliate-wordpress-1 bash -c "chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"`
  Run it as: `docker exec -w /var/www/html -e PAGER=cat affiliate-wordpress-1 wp <command> --allow-root --user=1`
- Admin user: `amran` (ID 1). Password was reset via `wp_set_password()` through
  `wp-load.php` — if you need to reset it again:
  `docker exec affiliate-wordpress-1 php -r "require('/var/www/html/wp-load.php'); wp_set_password('NEWPASS', 1);"`
- Search engines are currently **blocked** (`blog_public = 0`, "Discourage search
  engines" is checked, and pages render `noindex,nofollow`). That's correct for now
  since this is local/demo content — must be turned back on before any real launch.

## Theme & demo content

- Active theme: **ReHub** (`rehub-theme`, parent) with child theme
  `rehub-blankchild`. Commercial theme (ThemeForest) — license is registered in the
  `Rehub_Key` wp_option (`tf_username` / `tf_purchase_code`). Don't touch/clear that
  option; the theme's demo importer and update checks are gated behind it and will
  hard-block (calls `exit()`) if it's missing or invalid.
- Demo content imported: **ReWise** (one of ~19 bundled ReHub demo skins, index 12 in
  `wp ocdi list`), via the theme's own "Import Demo" screen
  (`/wp-admin/admin.php?page=import_demo`), which wraps the **One Click Demo Import**
  plugin. The importer's file list is only built when `$_GET['page'] === 'import_demo'`
  or during its own AJAX call — so scripting it via WP-CLI needs
  `--exec='$_GET["page"]="import_demo";'` before `wp ocdi list` / `wp ocdi import`.
- The ReWise demo is a **price-comparison/shop demo** — its copy, stats ("compare
  1,233 products"), Indian e-commerce deal posts (Jabong/Paytm/etc.), and lorem ipsum
  sections are all placeholder and need to be replaced with real content (see Roadmap).

## Key plugins

| Plugin | Role |
|---|---|
| `rehub-framework` | Theme's core framework (required by rehub-theme) |
| `content-egg` | Affiliate offer/deal aggregation — **the core affiliate engine**. Free tier modules only: Amazon, Admitad, Affilinet, CJ (Commission Junction), Skimlinks, Viglink, Coupon, Feed/RSS, YouTube, image sources (Pexels/Pixabay/Unsplash/Google Images). No AliExpress/eBay/Walmart/Awin — those need Content Egg Pro. |
| `elementor` | Page builder for custom layouts |
| `greenshift-animation-and-page-builder-blocks` + `greenshiftgsap` | Block-editor page builder + GSAP animation addon (update available: 3.7.5 → 3.7.7) |
| `all-in-one-seo-pack` (AIOSEO) | SEO meta, sitemap, schema |
| `google-analytics-for-wordpress` (MonsterInsights) | Analytics — not yet connected to a real GA property |
| `wpforms-lite` | Contact/lead forms |
| `one-click-demo-import` | Demo import engine (see above) |
| `coming-soon` | Coming-soon/maintenance mode plugin — currently not blocking the front end, but check its settings before launch |
| `envato-market` | ThemeForest/CodeCanyon plugin/theme update channel |
| `akismet`, `hello` | Inactive, bundled defaults — safe to delete if unused |

## Conventions / gotchas

- Don't try to bypass the ReHub license check (`rehub_before_import_setup` /
  `rehub_import_files` in `wp-content/themes/rehub-theme/admin/demo/import-demo.php`).
  It calls the vendor's LicenseBoxAPI over the network; a missing/invalid license is
  intentional vendor DRM, not a bug.
- Theme options (colors, header style, logo URL, footer text, etc.) live in the
  `theme_mods_rehub-blankchild` option, seeded from
  `wp-content/themes/rehub-theme/admin/demo/rewise-theme.json` at import time — edit
  via Customizer/Theme Options screens, not by hand-editing that JSON after the fact.
- Local dev DB/WP passwords are intentionally weak (`changeme`) per `.env` — fine for
  local, must be rotated before any shared/production environment.

## A CSS specificity gotcha worth remembering

The child theme's `theme.json` generates a global style rule shaped like
`:root :where(p){font-size:16px;line-height:28px}` (standard WP block-theme
global styles). `:where()` always carries **zero specificity** — so it's easy
to assume "remove my override and it'll just inherit from the parent," but
that's wrong: a rule that directly targets `p` (even at zero specificity)
still beats inherited values from an ancestor, since inheritance only kicks
in when *nothing* matches the element itself. Result: deleting
`.rgh-post p{line-height:1.7}` didn't make paragraphs inherit `line-height:1.5`
from `.rgh-home` — it exposed the theme's global `28px` instead, which had
been sitting there unopposed the whole time. Fixed by adding
`.rgh-home p{line-height:normal}` (real specificity, beats `:where()`
outright) rather than by removing a rule and hoping for inheritance. Verified
with a computed-style check via Playwright (`getComputedStyle(p).lineHeight`),
not just eyeballing a screenshot — the difference is too subtle to trust by
eye. If a future "remove/change this style" request doesn't visibly do
anything, suspect a `theme.json` global-styles rule sitting underneath before
assuming the edit didn't take.

## Follow-up: matched remaining reference-mockup details 1:1

- `review-list` padding: `6px 24px` → `20px 24px` (per direct user-supplied CSS).
- **Quick Links / Popular Categories**: icons now sit in colored circles
  (per-item tint, matching the category palette) instead of a flat gray box,
  and each row got a right-aligned chevron `>` — both missing before.
- **Newsletter card**: was tinted blue; reference uses a plain white card like
  the others, with a small icon next to the "Newsletter" title instead of a
  colored background. Fixed to match.
- **Featured card**, now matching 1:1: circular score gauge → 5-star rating +
  numeric + "(N reviews)" (same `rgh_stars_html()` used in Latest breakdowns);
  aspect-chip pills → an icon/dot + label + sentiment "mini-stat" row
  (`rgh_mini_stats_html()`, new function, reusing the same `_rgh_aspect_chips`
  data, just laid out like the reference's Performance/Battery/Value row);
  added a bold blue "hook" lead-in phrase before the excerpt (pulled from the
  first aspect chip's label, e.g. "**Scroll wheel.** One of the most..."); and
  a dark "Most Recommended" badge pill overlaid on the product photo (top
  right), matching the reference's "Best Overall" badge placement.
- **Category photos** ("every category should have nice photos"): added
  `rgh_category_photo( $term_id )` — queries the highest-scored post in that
  category and uses its featured image. Applied to both the main "Browse by
  category" row (circular photo instead of icon+tint) and the sidebar
  "Popular Categories" list (now shows 4 instead of 3, matching reference).
  Falls back to the icon+tint treatment if a category has no posts yet — this
  is fully dynamic, not hardcoded photo assignments, so it stays correct as
  content changes.

## Follow-up: two different column splits, not one shared class

"Featured review" (8:4) and "Latest breakdowns" (9:3) needed *different*
sidebar ratios, but both used the same `.content-grid` class (`1.9fr 1fr`).
Added `.content-grid-wide` as a modifier (`3fr 1fr` ≈ 9:3), applied only to
the Latest Breakdowns wrapper (`class="content-grid content-grid-wide"`) —
Featured review's grid untouched, still `1.9fr 1fr` (≈8:4).

## Follow-up: senior-dev consistency audit (padding/color/radius)

User asked for a general "make it professional" pass rather than a specific
bug. Audited via `grep` (hardcoded hex colors, every `border-radius` value in
use, every `@media` block) instead of just eyeballing — found real issues:

- **Button radius**: search-box button and newsletter button were `7px`,
  Featured CTA was `8px` — same component type, no reason to differ. Unified
  to `8px`.
- **Hardcoded star color** `#F5A623` didn't actually match the amber token
  (`#F59E0B`) — same bug pattern as the earlier line-height issue (a literal
  value silently drifting from the token it was supposed to represent). Now
  `var(--rgh-amber)`.
- **Card radius**: sidebar cards + consensus-callout were `10px` while
  review-list/featured/review-post cards were `12px`/`16px`. Unified onto the
  `--rgh-radius` (12px) / `--rgh-radius-lg` (16px) tokens everywhere a
  hardcoded value matched one of them.
- **Three separate `@media (max-width:900px)` blocks** scattered through the
  homepage's `<style>` (one for `.cat-row`, one inline for `.featured`, one
  larger consolidated block) — merged into one. Was harmless (no conflicts)
  but sloppy and easy to lose track of on the next edit.
- **Footer was pure black**, not our navy ink token — the one dark element on
  the site not using `--rgh-ink` (#0F172A). Set via
  `rehub_option['footer_color_background']` (same "reads from `rehub_option`,
  not `theme_mods`" gotcha as the logo/footer-text fix from phase 2). Note:
  the very bottom copyright strip is a separate, still-darker theme element
  not addressed here — minor, left as is.

## Follow-up: double-padding bug + missing hero gradient

- **Oversized gap before "How we curate"**: `.method` (that section) had
  *both* the base `.rgh-home section{padding:60px 0}` *and* its own
  `.method .col{padding:56px 24px}` — stacking to 116px top+bottom instead
  of one or the other. Fixed with `.method{padding:0}` so only the
  `.col`-level padding applies. Checked for the same pattern elsewhere
  (`grep` for other `.col{padding` overrides) — this was the only offender.
- **Hero had no background** — the reference's `.home-hero` uses
  `linear-gradient(120deg, #eff6ff, #ffffff)`; ours sat on the plain page
  background. Added `background:linear-gradient(120deg,var(--rgh-blue-tint),
  var(--rgh-surface))` to `.hero` — `--rgh-blue-tint` is already `#EFF6FF`,
  an exact match to the reference's gradient start color.

## Follow-up: page order + layout split + icons-vs-photos split

Three corrections from the same coded reference:

1. **Section order**: Hero was followed by a standalone trust-strip band,
   *then* Categories. Reference has Categories immediately after Hero.
   Moved the trust-strip block down to sit after Categories instead (kept
   the content — reference doesn't have this band at all, but nothing asked
   to delete it, just reorder around it).
2. **Featured review + Quick Links now share an 8/4 split** (reused the
   existing `.content-grid` grid, ratio `1.9fr 1fr` ≈ `8/4`): Quick Links
   moved out of the Latest Breakdowns sidebar into its own sidebar next to
   Featured. Popular Categories + Newsletter stayed with Latest Breakdowns.
3. **Icons vs. photos, clarified per-location** (I'd made both photo-based
   after "every category should have nice photos" a few turns back — too
   broad): main "Browse by category" row → icon+tint only (matches the
   coded reference's emoji-in-circle category cards); sidebar "Popular
   Categories" → keeps real photos. Only `rgh_categories` (the shortcode
   backing the main row) changed — `rgh_popular_categories` untouched,
   still uses `rgh_category_photo()` with icon fallback.

## Follow-up: exact token/structure match against a real coded reference

User supplied a full coded reference this time (index.html + a single-review
page + styles.css), not just a screenshot — far more precise than eyeballing
an image. Pulled exact values and applied them:

- **Font swapped Manrope → Inter** (the reference's actual `@import` font)
  everywhere: rgh-site.css, homepage inline `<style>`, the Google Fonts
  `<link>`/`wp_enqueue_style` in functions.php, plus the two remaining
  `font-family:'Manrope'` declarations on form inputs.
- **Color tokens replaced with the reference's exact hex values** (was close
  before, now exact): `--rgh-muted:#475569` (was `#5B6B7F`), `--rgh-line:
  #E2E8F0` (was `#DFE5EC`), `--rgh-surface-2`/`--rgh-paper:#F8FAFC` (was
  `#EEF2F6`/`#F4F6F9`), `--rgh-green:#10B981` (was `#16A34A`),
  `--rgh-amber:#F59E0B` (was `#D97706`), `--rgh-rose:#EF4444` (was
  `#E11D48`). `--rgh-blue`/`--rgh-blue-ink`/`--rgh-ink` were already exact
  matches. Added `--rgh-radius:12px`/`--rgh-radius-lg:16px` tokens (the
  reference names these explicitly). Body `line-height` 1.5→1.65,
  `font-weight` 500→400 to match their `body{}` rule.
- **Featured card restructured**: the reference's `.featured-review` puts the
  image *first* (left column, `.8fr`, no padding — bleeds to the card edge)
  and content *second* (right, `1fr`, its own `padding:50px`) — ours had it
  mirrored (content left, image right) with padding shared across the whole
  card. Fixed: new `.featured-body` wrapper carries the content padding, the
  image column (`.featured-visual`) is now edge-to-edge with `overflow:
  hidden` on the parent handling the rounded corners. Shortcode markup order
  swapped to match (image div first, `.featured-body` second).

**Not done — flagged as a separate, larger task**: the reference's
single-review page (`single review` in their paste) has a much richer
template than our current `.rgh-post` box — sticky anchor nav, a Quick
Verdict card, a 4-item feature grid, a Performance section with its own
image, a Pros/Cons split panel, a "Who is it for" audience grid, a
Where-to-Buy retailer price-comparison table, a dark Final Verdict banner,
and an FAQ accordion. This is a genuine content-model expansion (retailer
prices, FAQ entries, audience segments don't exist as data yet), not a CSS
tweak — worth scoping as its own task, likely extending the "Product Review"
meta-box feature with new fields, rather than cramming into a styling pass.

## Follow-up: circles → rounded squares for photo-bearing tiles

User feedback: circular crops on real product photography look clumsy —
clips too much of a naturally rectangular photo. Rounded squares read
cleaner and show more of the image; better fit for photo tiles specifically
(vs. plain icons, where circles are fine). Changed, and sized up for more
presence:

- `.cat-item .ico` (category tiles): 60px circle → 76px square, `18px` radius.
- `.side-link .ico` (Quick Links + Popular Categories, same shared class):
  36px circle → 44px square, `11px` radius.
- `.featured-visual` (Featured card photo panel): 230px → 270px tall,
  `12px` → `16px` radius, to match the same bigger/rounded-square language.
- Left as circles (not photos, no complaint raised): `.newsletter h4 .ico`
  (small decorative icon) and `.mini-stat .dot` (a status dot, not an
  icon/photo container — circular is the right convention there).

## Follow-up: review post card container

`.rgh-post` (the wrapper div every review — the 30 seeded posts and anything
made via the meta-box feature — shares) now has its own fixed
padding/margin/border/background in `rgh-site.css`: `padding:28px 28px 32px
28px`, `margin:0 0 28px 0`, white surface + border + shadow, so every review
reads as a self-contained bounded card instead of bare text flowing into the
theme's post-content area. Single rule, applies everywhere `.rgh-post` is used.

## Adding a product review (the "feature")

The post editor has a **"Product Review (ReviewGeekHub)"** meta box (below the
content editor, on any regular post). Fill in:

- Consensus Score (0–10)
- Review count + Sources (e.g. "Amazon, Reddit, YouTube")
- Aspect chips — one per line, `Label|good` or `Label|mixed`
- What owners praise / Common complaints — one point per line
- The consensus (verdict paragraph)

Write the actual post body in the normal content editor below it. On publish,
the site automatically renders the same styled consensus box (score gauge,
chips, praise/complaint lists, verdict) at the top of the post — **no HTML
required** — and mirrors the score into ReHub's own native review fields
(`rehub_review_overall_score` etc.), so it gets real Schema.org
Review/AggregateRating markup and plays correctly with the theme's built-in
score-based sorting, exactly like a review made with ReHub's own "Review Box"
Gutenberg block.

Implementation: `wp-content/themes/rehub-blankchild/rgh-review-feature.php`
(meta box + save handler + a `the_content` filter that auto-renders the box —
keyed off `_rgh_score` being non-empty, so it never touches the 30 seeded
posts, which have hand-written HTML instead). Verified end-to-end with a throwaway
test post (visual render + schema output both confirmed, then deleted).

## Roadmap — making this production-ready, professional, and modern

Rough phases, roughly in order. Mark each `[x]` when finished, with a one-line note
on what was actually done.

1. [x] **Strip the "shop" framing.** Unpublish/delete Shop, Cart, Checkout, My account,
   "For vendors" (Testimonial/How to use/Donate Us/Catalog) — this is a content site,
   not a store. Keep Comparison/Coupon/Deal layouts; they're genuinely useful for
   affiliate content.
   - Done: trashed pages 703 (Shop), 704 (Cart), 705 (Checkout), 706 (My account),
     and 1126 (draft Refund and Returns Policy) — none were linked from any menu.
     Removed the "For vendors" half of the `text-3` footer widget
     (`footersecond` sidebar), keeping "For customers" intact.
2. [x] **Real branding.** Replace "Rewise" name/logo (currently hot-linked from
   `rewise.wpsoul.net`, the vendor's own demo server) with a real site name, logo,
   favicon, and color palette. Update `blogname`/`blogdescription`, footer copyright
   (currently "2016 Wpsoul.com Design"), and OG/social meta.
   - Done: working name **ReviewGeekHub** (provisional — icon mark is name-agnostic
     so a rename later is cheap). Built icon + light/dark logo lockups, saved to
     `wordpress/branding/`, brand-kit review at the artifact link shared in chat.
     Wired in: media-library import (attachment IDs 1174 icon / 1175 logo-light /
     1176 logo-dark), `site_icon` option, WP core `custom_logo` theme mod, and —
     the one that actually renders on the front end — `rehub_option['rehub_logo']`
     (ReHub reads logo/most options from the `rehub_option` row via
     `REHub_Framework::get_option()`, **not** `theme_mods_rehub-blankchild`; the
     theme_mods copy only affects the Customizer live-preview, is_customize_preview()
     path — don't repeat that mistake for other rehub_* options). Also updated
     `blogname`/`blogdescription`, the footer "About" widget + copyright text, and
     replaced literal "Re:Wise"/"Rewise" mentions in the live front page's content
     (post 1128, title renamed to "Home").
   - Not done yet: OG/social meta images, and the other lorem/demo sections on the
     homepage ("How our site is working", "1,233 products" stat) — that's Phase 3
     content work, not branding.
3. **Real content, real niche.** Replace demo deal posts (Jabong/Paytm/etc.) and
   lorem-ipsum sections with actual affiliate content in the chosen niche. Wire
   Content Egg to real affiliate accounts (Amazon Associates, CJ, etc. — apply for
   networks relevant to the niche) instead of demo data.
4. [x] **Compliance (partial).** Add an FTC-style affiliate disclosure (sitewide +
   per-post), Privacy Policy, Terms, and cookie consent if targeting EU/UK visitors.
   Outbound affiliate links should be cloaked (`/go/` redirects — Content Egg
   supports this) and marked `rel="sponsored nofollow"`.
   - Done: Affiliate Disclosure (1323), Privacy Policy (3) and Terms of Use (1324)
     already existed with real (non-lorem) copy from the phase-1 nav rebuild, but
     were **orphaned pages** — zero links to them anywhere on the site, which
     defeats the point. Fixed: added Privacy Policy + Terms links next to the
     existing Disclaimer/Mail Us links in the footer copyright bar
     (`rehub_option['rehub_footer_text']` — same option as the phase-2/5 footer
     gotchas). Added a short, non-intrusive per-post disclosure line ("We may earn
     a commission from links on this page. See our disclosure.") directly inside
     the auto-rendered consensus box in `rgh-review-feature.php`'s `the_content`
     filter, so it's "clear and conspicuous" near the affiliate content itself, not
     just buried in the footer — matches FTC guidance better than a sitewide-only
     disclosure. Note: this filter only fires for posts with `_rgh_score` set (the
     meta-box feature / pipeline-published posts) — it does **not** retroactively
     add the notice to the 30 seeded posts, which have hand-written HTML bypassing
     this filter entirely (see "Adding a product review" above for why).
   - Not done: cookie consent banner (no EU/UK targeting decided yet), `/go/`
     link cloaking and `rel="sponsored"` (there are no real affiliate-network
     links to cloak yet — Content Egg is still on demo data per item 3; the
     pipeline's own outbound links are plain `rel="nofollow noopener"` source
     citations, not monetized affiliate links, so `sponsored` would be inaccurate
     until real affiliate accounts exist).
5. [x] **Design modernization (homepage).** The ReHub demo look is dated (2016-era
   hero banner, generic icon-grid "how it works" section, stock chess-piece/lightbulb
   imagery). Rebuild key sections (hero, homepage feature blocks, footer) with
   Elementor or Greenshift for a cleaner, current layout; tighten typography/spacing;
   verify mobile responsiveness.
   - Done: replaced homepage (post 1128) content with a single `wp:html` block —
     hero (with functional `/?s=` search), trust strip, category row, featured
     "Consensus Score" card, latest-breakdowns list + sidebar, methodology band.
     White/light base only, no dark-mode variant (explicit choice, not an oversight).
     Fonts: Manrope + JetBrains Mono via Google Fonts `<link>` (not Elementor/
     Greenshift — plain scoped CSS under `.rgh-home` to avoid clashing with the
     theme's global styles). Kept the theme's real header/nav/footer untouched
     (already correctly branded from phase 2) rather than duplicating them.
   - Positioning correction baked into the copy: this site **aggregates real user
     reviews** (Amazon/Reddit/YouTube) — it does not buy/test products itself. Score
     is labeled "Consensus Score" with a source count, not an editorial verdict.
     Same `rehub_option` vs `theme_mods` gotcha from phase 2 bit again here
     (`rehub_footer_text` is also read from `rehub_option`, not theme_mods) — fixed.
   - Done (2026-09-19, follow-up): the red main-nav bar is fixed. It came from
     `rehub_option['rehub_custom_color_nav']` (`#eb0909`, a literal theme-demo
     red, same "reads from `rehub_option`" pattern as the logo/footer options
     above) — changed to `#0F172A`, the same `--rgh-ink` navy the footer
     already uses, so header and footer now bookend the page consistently
     instead of clashing with a leftover demo color. White nav text/hover
     states already had enough contrast against navy, no font-color option
     change needed. Left alone: the small orange active-tab underline is the
     theme's global "main color" accent (`rehub-main-color`), used in many
     places site-wide (buttons, badges, etc.) — recoloring the nav
     specifically didn't require touching that, and doing so would be a much
     broader, separate change.
   - Follow-up: the homepage is no longer static HTML. "Browse by category",
     the "Most Recommended" featured card, "Latest breakdowns", and the sidebar
     "Popular Categories" are now real WordPress shortcodes
     (`[rgh_categories]`, `[rgh_featured]`, `[rgh_latest_reviews limit="4"]`,
     `[rgh_popular_categories]`) querying live posts/terms — defined in
     `wp-content/themes/rehub-blankchild/rgh-shortcodes.php`, embedded directly
     in the homepage's `wp:html` block (shortcodes process fine inside a Custom
     HTML block since `do_shortcode` runs on the fully assembled `the_content`
     output, not per-block). Featured card picks the single highest
     `rehub_review_overall_score`. Still static: sidebar "Quick Links" (Best
     Rated / Compare Products need real archive pages/query logic first).
   - Follow-up: "Latest breakdowns" row design reworked per a user-supplied
     reference mockup — one unified white panel with hairline dividers
     between rows instead of per-row boxed/shadowed cards. Briefly tried a
     5-star rating display (`rgh_stars_html()`, still defined in
     rgh-shortcodes.php but unused) before reverting per feedback: kept the
     circular gauge, but pulled it into its own third grid column
     (`.review-score`, right-aligned) instead of inline under the title —
     `review-row` is now `112px 1fr auto` (photo / text / score), so the left
     side of every card is the product photo alone. Then consolidated further:
     date and "Read breakdown" (previously in the middle column's `.foot` row)
     moved into the same right-side `.review-score` stack as the gauge/count,
     all at smaller font sizes with a tight 1px gap between lines — middle
     column is now just eyebrow/title/excerpt.
   - Follow-up: re-checked against the actual reference file (found on disk at
     `/Volumes/sourcecode/Pers/affiliate/ChatGPT Image Sep 15, 2026, 09_30_00
     PM.png` — the user had referenced it via a git-diff-style paste, not a
     direct path). The reference uses **one sans-serif family everywhere, no
     monospace at all** — ours had JetBrains Mono on every eyebrow, date,
     review count, gauge number, and tag-pill. Stripped `font-family:
     'JetBrains Mono'...` from every rule in both `rgh-site.css` and the
     homepage's inline `<style>` (two-pass sed — some rules had it as the
     last property with no trailing semicolon, needed a second pattern).
     Also dropped the now-unused JetBrains Mono weight from the Google Fonts
     `<link>`/`wp_enqueue_style` URL (functions.php + homepage block) since
     nothing loads it anymore, and changed the hero eyebrow's separator from
     "·" to "•" to match the reference's exact bullet-separated pattern.
     Verified on both the homepage and an individual review post (shared
     rgh-site.css affects both) — confirmed clean, single Manrope family
     throughout.
   - Follow-up (superseding the two notes above): user asked to match the
     reference mockup's *exact* pattern, which turned out to put the rating
     back inline under the title as stars + numeric + "(N reviews)" — and
     the right-side column only holds "Read breakdown →" + date (top-aligned,
     stacked), no score badge there at all. Re-flipped to that: `rating-line`
     (stars) is back under the `h3`, `.review-score` right-column removed in
     favor of `.review-meta` (just read link + date). `rgh_gauge()` is no
     longer used in this shortcode — it's still the display on the Featured
     card and individual review posts.
   - Follow-up pass: hero now uses a real CC0 photo (Wikimedia Commons, credited
     inline) instead of an SVG mockup — attachment ID 1179. "Browse by category"
     redesigned from a plain scrolling icon strip to a proper 6-column grid with
     distinct per-category tint colors and hover states, linking to real category
     archives (see phase 3 note below for the category term IDs). Shared design
     tokens/components (fonts, `.gauge`, `.aspect-chip`, `.consensus-callout`, etc.)
     were moved out of the homepage's inline `<style>` into
     `wp-content/themes/rehub-blankchild/rgh-site.css`, enqueued site-wide from the
     child theme's `functions.php` — so individual posts can reuse the same
     `.rgh-home`/`.rgh-post` classes without duplicating a big style block per post.

3. [x] **Real content, real niche (partial — illustrative, not the final niche).**
   Replace demo deal posts and lorem-ipsum sections with actual affiliate content.
   - Done: seeded 30 "consensus review" posts (IDs 1181–1210) across 6 real
     categories created for this — Electronics (24), Home & Kitchen (25),
     Headphones (26), Wearables (27), Fitness (28), Outdoor (29). Each post uses
     the shared `.rgh-post` layout: a Consensus Score callout, aspect chips
     (praised/mixed), "What owners praise" / "Common complaints" lists, and a
     verdict box — all written in the aggregated-review voice (no first-person
     testing claims). Generator script pattern: `seed-reviews.php` (data → 
     `wp_insert_post`), fixed once via `fix-reviews.php` (chip labels were
     originally trimmed from full sentences and truncated mid-word — fixed by
     giving chips their own short labels instead of trimming prose).
   - Not done: this is still illustrative placeholder content (real products,
     plausible-but-invented review counts/scores) standing in for a niche that
     hasn't been chosen yet — not sourced from actual Amazon/Reddit/YouTube data.
     The homepage's "Latest breakdowns" section is still static hardcoded HTML,
     not a live query against these 30 posts — wiring that up is a follow-on step
     if/when the niche and real content pipeline are decided.
   - Follow-up: all 30 posts now have a real featured photo (set as WP featured
     image, attachment IDs 1241-1299) sourced from Openverse (Wikimedia Commons/
     StockSnap/Flickr/rawpixel, CC0/CC-BY, commercial-use filtered). These are
     category-representative photos, not official manufacturer photography (that's
     not freely licensed) — e.g. the Dyson review uses a generic/Hoover-branded
     stick vacuum photo. Each post has a small "Photo: {creator} · {license}"
     credit line inserted right after the opening `.rgh-post` div for attribution.
     Source/process notes: `find-images.py` + manual review (Openverse's search
     relevance is mediocre — several auto-picks were wrong, e.g. Whoop 4.0 first
     got a news-website screenshot instead of a product photo, caught by visually
     spot-checking and replaced). Some source images arrive as WebP despite a
     `.jpg` URL (rawpixel) — `sips` can't convert WebP on this machine, used
     `dwebp` first. Full manifest with URLs/license/creator per post:
     `wordpress/branding/product-images/final_manifest.json` (also in scratch, not
     yet copied into the repo — copy it over if this needs to be reproducible).
   - Follow-up (2026-09-19): the **real, automated content pipeline now
     exists and has published real posts** — see "Stage 0 — Master Pipeline"
     above. Two genuinely real products (Sony WF-1000XM6, Apple AirPods
     Pro 3 — chosen by the pipeline's own discovery step, not hand-picked)
     are live with real sourced evidence, real sideloaded photos, and no
     invented facts. Still not the final decided niche — "wireless earbuds"
     was this session's test category, not a niche decision — and still only
     2 posts, not a real content volume. Running the pipeline at its
     `product_count: 10` default for real, and deciding the actual niche,
     are the next steps here, not further pipeline engineering.
6. **SEO.** Turn off "discourage search engines" and fix `noindex,nofollow` only once
   real content is ready. Configure AIOSEO properly (titles/meta templates, sitemap,
   review schema via Content Egg/ReHub's review markup).
7. **Analytics & deliverability.** Connect MonsterInsights to a real GA4 property.
   Replace the demo Mailchimp embed with a real ESP (Mailchimp/Brevo/etc.) and
   configure real outbound SMTP (WPForms + comment notifications currently have
   nowhere real to send).
8. [x] **Performance & housekeeping (partial).** Add a caching layer (host-dependent)
   and image optimization; update `greenshiftgsap` (3.7.5 → 3.7.7); remove
   `hello.php`; decide on Akismet (re-activate if comments stay open, since spam is
   likely once public).
   - Done (2026-09-19): updated `google-analytics-for-wordpress`/MonsterInsights
     (11.2.0 → 11.3.0) and `wpforms-lite` (2.0.1.1 → 2.0.2), both from wordpress.org
     with no site breakage (verified homepage + a review post still 200 after).
   - Not done / blocked: `greenshiftgsap` update **failed** ("Update package not
     available") — it's a commercial GreenShift addon gated behind its own EDD
     license key (same `EddLicensePage.php` pattern as the ReHub theme's vendor
     licensing, not a wordpress.org plugin), so `wp plugin update` can't reach it
     without real license credentials. `hello.php`/Akismet are already inactive
     (functionally harmless either way) but not deleted/decided. Caching layer and
     image optimization need a real hosting decision first (item 10) — premature to
     configure against local Docker.
9. **Security/launch hardening.** Rotate DB/WP-admin passwords, disable `WP_DEBUG` in
   prod, set real HTTPS, decide on `coming-soon` plugin for a staged launch.
10. **Production deployment.** Not yet decided — no host, deploy pipeline, or
    production URL exists yet. Needs its own decision (managed WP host vs. own
    VPS/Docker) before this phase can start.

# Deployment Plan — Local Docker WordPress → Hostinger Cloud Hosting

## Context

- **Local dev**: WordPress + MySQL run in Docker (`docker-compose.yml`), with wp-content
  living inside a Docker-managed volume (`wordpress_data`) — not in git. Custom code
  (`wordpress/mu-plugins/`, `wordpress/theme-files/`) is tracked separately in this repo.
- **Target**: Hostinger **Cloud Hosting** — hPanel-based, no Docker, but SSH + WP-CLI
  are available.
- **Status**: first deploy (no existing site on Hostinger to preserve). No git commits
  exist yet in this repo.

The core idea: **code ships via git/CI, content (DB + uploads) migrates once and then
lives in production.** Local Docker and production Hostinger are different environments
by design — don't try to make them identical, just make sure the same custom code runs
on both.

---

## 1. Repo hygiene (before any commit)

No `.gitignore` exists yet. Add one covering:
- `.env` (real secrets — currently placeholders, but treat as sensitive)
- `.DS_Store`
- `.playwright-mcp/`
- `affiliate_hero_photo/` (currently `700` perms — looks personal, not site content)
- The loose `ChatGPT Image ...png` at repo root (move into `wordpress/branding/` if it's
  actually needed, otherwise drop it)

First commit should contain only what's actually deployable/versionable:
- `docker-compose.yml`
- `.env.example`
- `wordpress/mu-plugins/`
- `wordpress/theme-files/`
- `wordpress/branding/`
- `wordpress/CLAUDE.md`
- `workflows/` (n8n pipeline JSON)

## 2. Deploy surface mapping (no restructuring needed)

`wordpress/theme-files/` is already flat, and it maps flat-to-flat onto the active
child theme's directory on the server — no local restructuring required. The active
theme is **ReHub** (`rehub-theme`, parent) with child theme **`rehub-blankchild`**
(confirmed via `wordpress/CLAUDE.md`, which references files like
`wp-content/themes/rehub-blankchild/rgh-review-feature.php` directly).

```
Local repo path              →  Server path
wordpress/mu-plugins/        →  wp-content/mu-plugins/
wordpress/theme-files/       →  wp-content/themes/rehub-blankchild/
```

`wordpress/rgh-secrets.php` is gitignored and deliberately **not** part of this deploy
surface — see §5.

## 3. Stand up Hostinger

- Install WordPress via the hPanel installer.
- Install and activate the **same premium theme + version** the custom files hook into
  (REHub, based on the `rgh_` prefixes throughout the code).
- Confirm SSH access and WP-CLI work: `ssh` into the box, run `wp --info`.

## 4. One-time content migration (DB + uploads) — done

Executed 2026-09-25. Actual steps used (slightly different from the original plan,
worth keeping for reference if this is ever needed again):

1. `mysqldump` isn't present in the `wordpress:latest` image's PATH — `wp db export`
   fails there. Ran `mysqldump` directly against the `wordpress-db` container instead:
   `docker exec affiliate-wordpress-db-1 sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'`.
2. **Checked plugins first** (not in the original plan) — prod already had some plugins
   at *newer* versions than local (`elementor`, `envato-market`) plus Hostinger's own
   management plugins (`litespeed-cache`, `hostinger-ai-assistant`, etc.) that don't
   exist locally at all. A blanket rsync of `wp-content/plugins/` would have downgraded
   the former and could have clobbered the latter. Diffed `wp plugin list` on both sides
   and only rsynced the plugins genuinely missing on prod (`akismet`,
   `all-in-one-seo-pack`, `coming-soon`, `google-analytics-for-wordpress`, `hello`,
   `woocommerce`, `wpforms-lite`) — `hello` is a single `hello.php` file, not a
   directory, worth remembering.
3. Synced `wp-content/uploads/` (~200 MB, 1292 files) via `docker cp` out of the
   container + `rsync` to prod — took >5 min for the initial pass (many small files);
   re-running the same `rsync` command resumed/completed cleanly since it skips
   already-transferred files.
4. `wp db import` + `wp search-replace 'http://localhost:8080' 'https://reviewgeekhub.com' --all-tables`
   on prod (344 replacements). Re-ran `wp option update siteurl/home` explicitly as a
   backstop — turned out to be a no-op, search-replace already covered it.
5. `wp rewrite flush --hard` + inspected `.htaccess` — **the local empty-rewrite-block
   bug did not carry over**; prod's LiteSpeed-managed `.htaccess` got real `RewriteRule`
   lines on the first flush. Verified with `curl -I` on both the homepage and a
   real post permalink → both `200`.

After this step, **production's database is the source of truth**. Content changes
happen directly against prod (wp-admin or WP-CLI over SSH), not by re-pushing the local
DB. This was a one-time migration, not something CI does — the `deploy` job in §5 still
only ever touches `mu-plugins/` and the child theme.

## 5. Git-based CI/CD for code only — implemented

The `deploy` job in `.github/workflows/ci.yml` runs on every push to `master` (after
`php-lint` passes) and rsyncs **only**:
- `wordpress/mu-plugins/` → `wp-content/mu-plugins/`
- `wordpress/theme-files/` → `wp-content/themes/rehub-blankchild/`

It never touches WP core, `uploads/`, or the database. `--delete` is scoped to just
those two directories, so removing a file from either path in the repo removes it on
the server too on the next deploy.

Required GitHub Actions repo secrets (Settings → Secrets and variables → Actions):
- `HOSTINGER_SSH_HOST`, `HOSTINGER_SSH_PORT`, `HOSTINGER_SSH_USER` — from hPanel →
  Advanced → SSH Access
- `HOSTINGER_SSH_KEY` — private half of a dedicated deploy keypair (not a personal key);
  the matching public key must be added to the same hPanel SSH Access page
- `HOSTINGER_DEPLOY_PATH` — absolute path to the site root, e.g.
  `/home/u123456789/domains/reviewgeekhub.com/public_html`. **Do not assume
  `~/public_html` is this site's docroot** — on a multi-site Hostinger account
  `~/public_html` is a symlink to whichever domain was set up first (confirmed via
  SSH: it pointed at an unrelated domain here). Always use the explicit
  `~/domains/<domain>/public_html` path.

Local Docker stack remains the dev/staging sandbox — it doesn't need to resemble
prod's full file layout beyond the `wp-content` subtree that actually ships.

## Flag for later

- `wordpress/mu-plugins/rgh-ingest-api.php` (n8n content ingest endpoint) currently only
  makes sense against `localhost`. Once prod exists, this endpoint and its auth need to
  point at the real domain before the n8n pipeline can publish to production.
- CSS duplication between `rgh-site.css` and the homepage's local `<style>` block
  (noted in `wordpress/CLAUDE.md`) — not a deploy blocker, but worth cleaning up before
  it diverges further across environments.

---

## Open items requiring manual action (not automatable from this machine)

- [x] Add the generated deploy public key to Hostinger hPanel → Advanced → SSH Access
- [x] Set the 5 GitHub Actions repo secrets listed in §5
- [x] Install WordPress + ReHub theme + `rehub-blankchild` child theme on Hostinger —
      was already done on the account before this repo's CI/CD existed
- [x] Confirm WP-CLI availability over SSH — WP-CLI 2.12.0, same version as local
- [x] Run the one-time DB/uploads migration (§4) and plugin gap-fill
- [x] Domain DNS already pointed at Hostinger and serving — confirmed via
      `curl -I https://reviewgeekhub.com/` → `200`
- [x] Re-verify the permalink/rewrite `.htaccess` issue (see `wordpress/CLAUDE.md`) —
      did **not** carry over to prod; real `RewriteRule` lines generated on first flush
- [ ] One-time upload of a prod-specific `wordpress/rgh-secrets.php` via SFTP —
      different key than local, not pushed through CI (it's gitignored by design) —
      still open; `rgh-ingest-api.php` will 401 on every call until this exists on prod

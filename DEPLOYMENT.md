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

## 2. Reshape the deploy surface to mirror the server

`wordpress/theme-files/` is currently 3 loose PHP/CSS files, not a real theme directory.
For rsync-based deploys to work cleanly, restructure to mirror the actual WordPress path:

```
wp-content/
  mu-plugins/
    rgh-ingest-api.php
  themes/
    <theme-name>-child/
      rgh-review-feature.php
      rgh-shortcodes.php
      rgh-site.css
```

This matters because the deploy script will rsync a local path to the *same* path on
the server — ambiguous flat folders make that fragile. Confirm exactly where each file
is currently loaded from (functions.php include? mu-plugin? child theme?) before moving
anything.

## 3. Stand up Hostinger

- Install WordPress via the hPanel installer.
- Install and activate the **same premium theme + version** the custom files hook into
  (REHub, based on the `rgh_` prefixes throughout the code).
- Confirm SSH access and WP-CLI work: `ssh` into the box, run `wp --info`.

## 4. One-time content migration (DB + uploads)

This happens **once**, since this is the first deploy:

1. Export the DB from the local `wordpress-db` container.
2. Run `wp search-replace` on the dump: local URL (`localhost:8080`) → production domain.
3. Transfer the DB dump and `wp-content/uploads/` to Hostinger via rsync/SFTP.
4. Import the DB via WP-CLI on Hostinger, flush rewrite rules
   (`wp rewrite flush --hard`).
5. **Verify pretty URLs return 200 on the new host.** The local environment already hit
   a bug where `permalink_structure` + rewrite flush reported success but `.htaccess`
   silently kept an empty rewrite block (see `wordpress/CLAUDE.md`) — don't assume this
   carries over cleanly; check it explicitly on Hostinger.

After this step, **production's database is the source of truth**. Content changes
happen directly against prod (wp-admin or WP-CLI over SSH), not by re-pushing the local
DB.

## 5. Git-based CI/CD for code only

- Create a private GitHub repo (or push this local repo to one).
- Add a deploy SSH key to Hostinger: hPanel → Advanced → SSH Access.
- Store the private key + host/user as GitHub Actions secrets.
- GitHub Actions workflow, triggered on push to `main`:
  - rsync **only** `wp-content/mu-plugins/` and the child-theme folder to the
    corresponding paths on the server.
  - Never rsync WP core, `uploads/`, or the database from CI.
- Local Docker stack remains the dev/staging sandbox — it doesn't need to resemble
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

- [ ] Add SSH deploy key to Hostinger hPanel
- [ ] Install WordPress + theme on Hostinger
- [ ] Confirm WP-CLI availability over SSH
- [ ] Set GitHub Actions repo secrets (SSH host/user/key)
- [ ] Run the one-time DB/uploads migration
- [ ] Point domain DNS at Hostinger (if not already)

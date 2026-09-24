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
  `/home/u123456789/domains/reviewgeekhub.com/public_html`

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

- [ ] Add the generated deploy public key to Hostinger hPanel → Advanced → SSH Access
- [ ] Set the 5 GitHub Actions repo secrets listed in §5
- [ ] Install WordPress + ReHub theme + `rehub-blankchild` child theme on Hostinger, and
      create empty `wp-content/mu-plugins/` and `wp-content/themes/rehub-blankchild/`
      dirs if this is a fresh install (so the first CI deploy has somewhere to land)
- [ ] Confirm WP-CLI availability over SSH
- [ ] One-time upload of a prod-specific `wordpress/rgh-secrets.php` via SFTP —
      different key than local, not pushed through CI (it's gitignored by design)
- [ ] Run the one-time DB/uploads migration
- [ ] Point domain DNS at Hostinger (if not already)
- [ ] Re-verify the permalink/rewrite `.htaccess` issue (see `wordpress/CLAUDE.md`)
      actually resolves cleanly on Hostinger — not assumed fixed by the local workaround

# Porting Obitleague to obitleague.co.uk

Target: `server.threewalls.co.uk` (SSH port 22). Domain: `obitleague.co.uk`.
Written ahead of the move; check items off as they complete.
**Status: DEPLOYED 2026-09-27 — site live over HTTPS. Password rotation still open.**

## 1. Server prerequisites

- [x] PHP 8.2+ (server runs PHP 8.4.25 via Plesk) and MariaDB 10.11.
- [x] HTTPS certificate — site serves over https://obitleague.co.uk.
- [x] PHP `curl` extension and outgoing HTTPS to Wikidata (portrait sync ran
      live: 42/44 people updated).

## 2. Code deployment

- [x] Plugin deployed as `git archive` tarball (avoids server-side GitHub
      auth) → `wp-content/plugins/obitleague/` from main @ 9112887 (0.8.0).
- [x] pro-elements 4.2.3 shipped as tarball from Local (not on WP.org).
      elementor 4.3.2 + classic-editor 1.7.0 installed from WP.org.
- [x] Theme: hello-elementor 3.5.1 copied from Local. Gotcha: `wp plugin
      install hello-elementor` fails — WP.org hosts it as slug `hello`,
      but DB expects `hello-elementor` directory. Never rely on WP.org
      for this theme; ship the Local copy.

## 3. Database move

- [x] Dumped Local DB (34 tables incl. 15 wp_obitleague_* + forum tables).
- [x] Imported into wp_zjlts after dropping the 12 stock `FTgPKOteL_` tables;
      `table_prefix` switched `FTgPKOteL_` → `wp_` in wp-config.php
      (pre-change backup: /root/obitleague-wp-config-prefix-backup.php, mode 600).
- [x] Serialize-safe `wp search-replace`: 193 replacements
      (`http://obitleague.local` → `https://obitleague.co.uk`); catch-all
      pass found 0 stragglers; 0 `.local` rows remain.
- [x] Deleted `_elementor_css` (17 rows) / `_elementor_element_cache`
      postmeta, flushed transients + object cache + rewrites.
- [x] **Plesk gotcha that cost the 404s:** wp-cli `rewrite flush` does NOT
      write `.htaccess` under CLI (no Apache detected). The vhost is
      nginx→Apache (proxy to PHP-FPM via Apache), so Apache needs the file.
      Fix was writing the standard WP `.htaccess` block by hand
      (chown obitleague:psacln). If this ever recurs, also available:
      `/var/www/vhosts/system/obitleague.co.uk/conf/vhost_nginx.conf`
      with `try_files $uri $uri/ /index.php?$query_string;` + `plesk bin
      httpdmng --reconfigure-site obitleague.co.uk`.

## 4. First-boot data tasks (wp-cli on the server)

- [x] `wp eval-file wp-content/plugins/obitleague/tests/sync-portraits.php`
      — refetched portraits + occupations from Wikidata (checked 44,
      updated 42).
- [x] `wp cache flush`; verified over HTTPS: `/`, `/catalogue/`
      (48 people rendered), `/stats/` (257 plugin markup hits), `/forum/`
      (45 markup hits), `/register/`, `/join/`, `/wp-login.php` → 200;
      `/wp-admin/` → login redirect. `/league/N/` and `/team/N/` for
      **existing** leagues → 200; for unknown ids → 302 → `/standings/`
      (see the 500 note in the log below before trusting a 302 probe).

## 5. What must NOT ship

- [x] Demo content kept: 2026 demo season (12 backdated teams, 3 leagues,
      91 approved events) is disclosed demo data, clearly labelled for
      launch preview. Reset later with `tests/demo-reset.php` before
      inviting real players, if desired.
- [x] `work/lan-proxy.cjs` never shipped; no `X-Obit-Host` trust needed.
- [ ] **Admin password rotation: STILL OPEN.** User id 1 `guywall` still has
      the shared dev password. Change before publicly announcing the site:
      `wp user update 1 --user_pass=<new>` (wp-cli alias in §log below).

## 6. Feed pipeline note

- [x] `obitleague_feed_poll` cron confirmed NOT scheduled on live (BBC
      sources stay trial/paused until the terms check completes). Migration
      carried the paused state automatically; verified via
      `wp cron event list`.

## Deployment log — 2026-09-27

- Plugin via `git archive HEAD` (160K) + pro-elements (3.0M) scp'd to /root,
  extracted into plugins; md5 verified before extract.
- Old 12 `FTgPKOteL_` stock tables dropped (backup first:
  /root/obitleague-pre-migration-20260927-225420.sql.gz, 201K).
- Dump imported `--default-character-set=utf8mb4`; 34 tables live; prefix
  switched in wp-config.php.
- wp-cli alias on server:
  `/opt/plesk/php/8.4/bin/php /usr/local/bin/wp
  --path=/var/www/vhosts/obitleague.co.uk/httpdocs --allow-root`
- Uploads dir was ~empty on Local; portraits regenerate from Wikidata, so no
  media migration was needed.
- wp-config backup + pre-migration DB backup kept in /root on the server;
  staging tarballs + SQL dump deleted from /root after use.
- **Post-deploy bug found and fixed (7e4b5c0):** every league page returned
  500 — `league-detail.php` called `Standings_Service::count_current()`
  without importing the class. Route probes of `/league/1/` read 302 from
  the unknown-league redirect (which fires before the crash) and so looked
  healthy while leagues 5/6/7 were fataling on both local and live. Lesson:
  probe league/team pages with a league id that actually exists, not 1.
  Remaining open item: §5 admin password rotation.

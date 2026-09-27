# Porting Obitleague to obitleague.co.uk

Target: `server.threewalls.co.uk` (SSH port 22). Domain: `obitleague.co.uk`.
Written ahead of the move; check items off as they complete.

## 1. Server prerequisites

- [ ] PHP 8.2+ (the plugin uses `strict_types`, enums-free PHP 8.1+ syntax,
      `str_starts_with`), MySQL 8.x or MariaDB 10.6+.
- [ ] HTTPS certificate (Let's Encrypt) — the site must not ship HTTP-only.
- [ ] PHP `curl` extension (Wikidata sync) and outgoing HTTPS to
      `www.wikidata.org` / `commons.wikimedia.org`.

## 2. Code deployment

- [ ] `git clone https://github.com/guywall/Obitleague` on the server; the
      repo root **is** the plugin (`obitleague.php` at top level).
- [ ] Link or copy the plugin into `wp-content/plugins/obitleague/` and
      network-activate (single site is fine).

## 3. Database move

- [ ] Export from Local (MySQL on 127.0.0.1:10005) with a dump that includes
      the 13 `wp_obitleague_*` tables.
- [ ] Import on the server, then **search-replace** URLs:
      `obitleague.local` → `https://obitleague.co.uk`
      (wp-cli `search-replace --precise`, or serialize-safe tooling —
      Elementor JSON in `_elementor_data` is serialized and will corrupt
      with naive SQL replace).
- [ ] Delete `_elementor_css` and `_elementor_element_cache` postmeta after
      import (stale-cache gotcha — pages render empty otherwise), then
      flush permalinks.

## 4. First-boot data tasks (wp-cli on the server)

- [ ] `wp eval-file tests/sync-portraits.php` — refetches portraits +
      occupations from Wikidata into postmeta (URLs are regenerated,
      not migrated).
- [ ] `wp cache flush`; confirm `/catalogue/`, `/league/1/`, `/stats/`,
      `/team/8/` return 200 over HTTPS.

## 5. What must NOT ship

- [ ] Demo content decision: the 2026 demo season (12 backdated teams, 3
      leagues, 91 approved events) is **disclosed demo data** — either keep
      it clearly labelled for launch preview or reset with
      `tests/demo-reset.php` before inviting real players.
- [ ] `work/lan-proxy.cjs` is a LAN dev tool — never run it in production.
      The `X-Obit-Host` wp-config trust is loopback-only and harmless, but
      production should not need it: WordPress `siteurl`/`home` are set to
      the real domain directly.
- [ ] Admin password rotation: the shared dev password should be changed
      before the site is publicly reachable.

## 6. Feed pipeline note

- BBC RSS sources remain **trial** status (`work/source-register.md`):
  terms check is still PENDING. Either complete the terms check before
  enabling `obitleague_feed_poll` cron in production, or leave the Jobs
  cron unscheduled at launch (plan §6 requires human editorial review
  regardless).

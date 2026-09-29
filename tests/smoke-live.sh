#!/usr/bin/env bash
#
# Live route smoke pass for the Obitleague site.
#
# READ-ONLY: every request is a plain GET. Safe to run against production at
# any time; it changes nothing and only reports.
#
#   bash tests/smoke-live.sh                          # probe https://obitleague.co.uk
#   BASE_URL=https://staging.example bash tests/smoke-live.sh
#
# Built for the post-merge verification of the header workstream:
#
#   * double header  — exactly one of the legacy .ob-nav chrome or the new
#                      .ob-header mega menu may render on the same page;
#                      both at once is the duplication bug.
#   * stray output   — no line of served HTML may consist only of '?>'
#                      (the literal close-tag text echoed by Header.php).
#
# Everything else is a route-by-route status probe of the public surfaces.
# HTTP 5xx and connection failures fail the run; unexpected statuses on
# optional routes are warnings, not failures.
#
# Exit code: 0 only when the two bug checks pass AND no route hard-fails.

set -uo pipefail

BASE_URL="${BASE_URL:-https://obitleague.co.uk}"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

FAIL=0
WARNS=0

fail() { printf '    FAIL %s\n' "$*"; FAIL=1; }
warn() { printf '    warn %s\n' "$*"; WARNS=$((WARNS+1)); }
ok()   { printf '    ok   %s\n' "$*"; }

probe() { # probe <expected-status> <path>
	local expected="$1" path="$2" code
	code="$(curl -sS -o /dev/null -w '%{http_code}' -m 30 "$BASE_URL/$path" 2>/dev/null || echo 000)"
	case "$code" in
		"$expected") ok "/$path -> $code" ;;
		000)         fail "/$path -> connection failure" ;;
		5*)          fail "/$path -> $code (server error)" ;;
		*)           warn "/$path -> $code (expected $expected)" ;;
	esac
}

echo "== routes ($BASE_URL) =="

# Core public pages.
probe 200 ""
probe 200 "catalogue/"
probe 200 "people/"
probe 200 "people/?living=0"
probe 200 "archive/"
probe 200 "rules/"
probe 200 "standings/"
probe 200 "stats/"
probe 200 "forum/"
probe 200 "register/"
probe 200 "verify-email/"
probe 200 "my-leagues/"
probe 200 "join/"

# Account surfaces. The branded /login/ page belongs to the header/login
# workstream: pre-merge it 404s, post-merge it must render.
probe 302 "wp-admin/"
# /wp-login.php intentionally 302-redirects to the branded /login/ page.
probe 302 "wp-login.php"
probe 200 "login/"

# REST layer.
probe 200 "wp-json/obitleague/v1/people?per_page=1"

# Assets the browser actually requests. Pages can 200 while every stylesheet
# 403s, and header.min.js is the suffix the header registers off SCRIPT_DEBUG.
probe 200 "wp-content/plugins/obitleague/assets/obitleague.css"
probe 200 "wp-content/plugins/obitleague/assets/chrome.css"
probe 200 "wp-content/plugins/obitleague/assets/campaign.css"
probe 200 "wp-content/plugins/obitleague/assets/header.js"
probe 200 "wp-content/plugins/obitleague/assets/header.min.js"

echo "== header duplication (homepage HTML) =="
if ! curl -sS -m 30 "$BASE_URL/" -o "$TMP" 2>/dev/null; then
	fail "homepage fetch failed"
else
	nav_count="$(grep -c 'ob-nav__inner' "$TMP" || true)"
	header_count="$(grep -c 'ob-header__inner' "$TMP" || true)"
	foot_count="$(grep -c 'ob-foot__inner' "$TMP" || true)"

	if [ "$nav_count" -gt 0 ] && [ "$header_count" -gt 0 ]; then
		fail "DOUBLE HEADER: legacy ob-nav (x$nav_count) AND new ob-header (x$header_count) both render"
	elif [ "$nav_count" -eq 0 ] && [ "$header_count" -eq 0 ]; then
		fail "no site header rendered at all"
	else
		ok "exactly one header system renders ($([ "$nav_count" -gt 0 ] && echo "ob-nav x$nav_count" || echo "ob-header x$header_count"))"
	fi
	[ "$nav_count" -gt 1 ] && warn "ob-nav renders $nav_count times"
	[ "$header_count" -gt 1 ] && warn "ob-header renders $header_count times"

	case "$foot_count" in
		1) ok "footer renders once" ;;
		0) warn "no footer rendered" ;;
		*) warn "footer renders $foot_count times" ;;
	esac
fi

echo "== stray close-tag output =="
if [ -s "$TMP" ]; then
	stray="$(grep -cE '^[[:space:]]*\?>[[:space:]]*$' "$TMP" || true)"
	if [ "$stray" -gt 0 ]; then
		fail "stray '?>' text present in served HTML ($stray line(s))"
	else
		ok "no stray '?>' output"
	fi
fi

echo
if [ "$FAIL" -eq 1 ]; then
	printf 'smoke result: FAIL (%d warning(s))\n' "$WARNS"
	exit 1
fi
printf 'smoke result: PASS (%d warning(s))\n' "$WARNS"

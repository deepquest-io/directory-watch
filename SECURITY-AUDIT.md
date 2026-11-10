# Security audit — Directory Watch 1.0.0

**Version:** 1.0.1
**Date:** 2026-11-10 (1.0.0 audit) · 2026-11-11 (1.0.1 addendum)
**Scope:** `directory-watch.php`, `includes/class-dw-admin.php`, `uninstall.php`, `assets/`
**Method:** manual code review, static analysis (WordPress Plugin Check / WPCS), dynamic testing against WordPress 7.1.4 on PHP 8.5 (SQLite drop-in), live calls to the WordPress.org directory API
**Verdict:** no open vulnerabilities. Five defects found and fixed; three residual risks accepted and documented.

---

## 1. Findings

### 1.1 — HIGH (fixed): closed and removed listings were silently never reported

**Where:** `dw_query_directory()` in `directory-watch.php`

**What:** the directory API answers **HTTP 404** for both "slug not found" *and* "listing closed", each with a JSON body:

```
HTTP 404  {"error":"Plugin not found."}
HTTP 404  {"error":"closed","name":"…","closed_date":"2019-05-02","reason_text":"Guideline Violation"}
```

The client rejected every non-200 response as a transport failure (`WP_Error`), and the caller treats a transport failure as "keep the previous verdict, record the error, do not alert." The result: a plugin that closed or vanished would **never** be reported — the exact two events the plugin exists to detect would fail silently, and the dashboard would keep showing "Listed".

**Fix:** accept 200 **and** 404 as parseable answers; other codes remain transport errors. Verified against the live API for a known-closed slug (`password-reset-shield`) and an unknown slug.

**Regression test:** `closed plugin parses from 404`, `closed flag detected`, `closed date read from closed_date`, `real closed record -> status closed`.

---

### 1.2 — MEDIUM (fixed): theme authors returned as an object

**Where:** record builder in `dw_query_directory()`

**What:** the plugin API returns `author` as a string, but `themes/info/1.2` returns an object (`{"display_name":…,"user_nicename":…}`). Casting it produced a PHP `Array to string conversion` warning on every theme lookup and stored the literal string `Array` as the author. On the next run that compares against the real author name and raises a **false "New owner" alert** — the highest-severity alert the plugin can emit, triggered by nothing.

**Fix:** `dw_author_to_string()` coerces objects and arrays to a scalar, preferring `display_name`, then `user_nicename`, then `name`; non-scalars become an empty string. Comparison logic already skips empty authors, so a missing author can never produce a transfer alert.

**Regression test:** `theme author is a plain string`, `theme author not empty`.

---

### 1.3 — LOW (fixed): manual check had no throttle

**Where:** `DW_Admin::handle_check_now()`

**What:** "Check now" is capability- and nonce-gated, but nothing stopped a logged-in administrator (or an XSS on an admin session) from firing it repeatedly. Each press runs up to 100 outbound requests against `api.wordpress.org` — an inadvertent request amplifier against a shared public service.

**Fix:** a one-minute `dw_manual_cooldown` transient, with a UI notice when throttled. Cron runs are unaffected.

---

### 1.4 — LOW (fixed): mail subject not stripped of CR/LF

**Where:** `dw_maybe_notify()`

**What:** the subject interpolates the site name via `get_bloginfo( 'name' )`. WordPress sanitises that on input, so this was not exploitable in practice, but header injection defence should not depend on another component's validation.

**Fix:** `str_replace( array( "\r", "\n" ), ' ', $subject )` before `wp_mail()`.

---

### 1.5 — LOW (accepted): the concurrency lock is advisory

**Where:** `dw_check_batch()`

**What:** `get_transient()` / `set_transient()` is not atomic. Two requests arriving in the same instant can both observe an empty lock and both run a batch, up to 2 × `per_run` (default 25, hard cap 100) requests.

**Why accepted:** the damage is bounded by the per-run cap, the interval gate (items already checked inside the window are skipped), and the fact that only an authenticated administrator or a cron tick can start a batch. A `GET_LOCK()`-based mutex would need raw SQL and a persistent connection, which is a worse trade for a plugin whose main selling point is not touching the database.

---

### 1.6 — INFO (accepted): WP-Cron is triggerable by any visitor

**What:** WordPress fires due cron events on page loads, and anyone can request `wp-cron.php`. On a low-traffic site an attacker can therefore cause the check loop to run.

**Impact:** nil in practice. The interval gate means an item checked within `check_hours` (24 by default) is skipped, so a triggered run performs no outbound requests at all unless something is genuinely due. No writes, no user-visible effect.

---

## 2. Controls verified

| Control | Result | Evidence |
|---|---|---|
| Capability checks | Every admin action and screen gated on `manage_options` | `current_user_can` at 2 call sites (`handle_check_now`, `render_page`) |
| Nonces | Manual check uses `check_admin_referer()` + `wp_nonce_field()`; settings use the Settings API | Verified in code and in rendered HTML |
| Output escaping | All output passes `esc_html`, `esc_attr`, `esc_textarea` or `esc_url` | Manual sweep of every `echo` / `printf` |
| SQL injection | **No SQL in the plugin.** State uses the options API only | `grep $wpdb` → no matches |
| SSRF | Fixed host `api.wordpress.org`; slug forced through `sanitize_key()`; no endpoint setting | Tested with `../../etc/passwd`, `http://169.254.169.254/latest`, whitespace and Unicode payloads — every constructed URL kept host `api.wordpress.org` |
| Remote response handling | `json_decode( $body, true, 8 )`, 256 KB body cap, 8 s timeout, 1 redirect | Code review; size cap asserted in code path |
| Unserialization | None. JSON only, no `unserialize()` | `grep` → no matches |
| Code execution sinks | None. No `eval`, `exec`, `system`, `shell_exec`, `passthru`, `popen`, `proc_open`, `assert`, `create_function` | `grep` → no matches |
| Filesystem | No writes at all — no `file_put_contents`, `fopen`, `unlink`, `mkdir` | `grep` → no matches |
| Direct cross-site requests | All admin forms post to `admin-post.php` / `options.php`; redirects use `wp_safe_redirect()` | Code review |
| Open redirect | `wp_safe_redirect()` with a plugin-built `admin_url()` target only | Code review |
| Directory traversal | No file reads by path; slugs are `sanitize_key()`-normalised before use | Tested |
| Secrets / credentials | None stored, requested or transmitted | `grep` → no matches |
| Data minimisation | Stores slug, display name, version, author, listing status, timestamps. All already public | Code review |
| Autoload | State option saved with `autoload = false` | Tested (`state not autoloaded`) |
| Uninstall | Options, transients and cron cleared; multisite loops every site | Code review + `uninstall.php` |
| Multisite | Per-site options; cron per site; uninstall sweeps the network | Code review |
| i18n | Every user-facing string runs through the `directory-watch` text domain; translators comments present | Plugin Check clean |
| Direct file access | `defined( 'ABSPATH' )` guard in all three PHP files; uninstall uses `WP_UNINSTALL_PLUGIN` | Verified per file |

## 3. Static analysis

```
wp plugin check directory-watch
errors:   0
warnings: 0
```

Run against the built zip (the shipping artifact), not the development tree — the source directory also contains `.gitignore` and `bin/build.sh`, which Plugin Check flags and the build excludes.

## 4. Dynamic testing

65 assertions, all passing, covering slug derivation, author/name normalisation, every verdict branch (first sight, closed, removed, transferred, renamed, re-listed, unchanged), SSRF host pinning, the settings sanitiser, live API queries for listed / unknown / closed / theme slugs, batch state persistence, the concurrency lock, drift detection end-to-end, pruning of uninstalled items, email gating, admin rendering under `E_ALL`, cron registration and autoload.

Two defects (1.1 and 1.2) were found by these tests, not by review — both were marked as expected passes until the assertions were written.

## 5. Residual risk summary

| Risk | Severity | Status |
|---|---|---|
| Double batch under a simultaneous request race | Low | Accepted, bounded by per-run cap |
| Visitor-triggered cron | Info | Accepted, interval gate means no requests |
| Depends on `api.wordpress.org` availability | Info | Failures never produce false alerts — verified |
| An attacker who can edit plugin files | — | Out of scope: that is full site compromise |
| Single-file plugins whose directory slug differs from the filename are reported as "Not in directory" | Info (functional) | Accepted. Errs towards silence — no false alert. Multi-file plugins unaffected |

## 6. Reproducing

```bash
# static analysis
wp plugin check directory-watch

# functional suite (requires a WordPress install with the plugin active)
wp eval-file dw-tests.php

# the specific regression for finding 1.1
wp eval 'var_dump( dw_query_directory( "plugin", "password-reset-shield" ) );'
# expect: array with error => "closed", closed => "2019-05-02"
```

---

## 7. Addendum — 1.0.1

Version 1.0.1 exists because of a defect that only appeared once the plugin ran on a real, slow host with a large inventory. It is recorded here because it is the kind of failure a static review does not catch.

### 7.1 — MEDIUM (fixed): a long batch was killed mid-request, wedging the plugin

**Where:** `dw_check_batch()` and `DW_Admin::handle_check_now()`

**What:** the manual check requested up to 100 items in a single admin request. On a site with 47 installed plugins and themes behind a host with a 30-second execution limit, the request was terminated part-way through. The consequences compounded:

* `dw_lock` (a 5-minute transient, set at the start of the run) was never cleared, so every subsequent run returned `checked = 0` and did nothing.
* `dw_manual_cooldown` (60 seconds) was re-armed by each new press, so the UI reported "throttled" and the operator saw an empty inventory with no explanation.

Net effect: a large site could appear to run a check that silently never completes, with stuck state and no error surfaced. A plugin whose entire job is to notice silent drift must not fail silently itself.

**Fix:**

* A soft wall-clock budget (20 seconds by default) stops the loop cleanly before the host kills the request; the run finishes its bookkeeping and releases the lock.
* The manual action now reports how many items are still queued (`&left=N`) so a large site can be completed over several presses.
* The manual cooldown dropped from 60 to 15 seconds — long enough to stop request amplification, short enough to let an operator continue immediately.
* `uninstall.php` now also removes `dw_manual_cooldown`, so a removal never leaves stray state.

**Verification:** on the production host that exposed it, a 47-item inventory now completes in two clicks, the lock is released on both, and the counters settle at 0 unchecked.

### 7.2 — Corrected behaviour confirmed in the field

A live validation run against a site with 47 installed plugins and themes produced: 39 listed, 3 closed (each reported with its closure date and stated reason), 5 not-in-directory, 0 unchecked. The three closures were genuine, years-old directory actions against plugins still installed and running — exactly the silent condition this plugin exists to surface. No false positives were raised for premium or custom code.

### 7.3 — What the live run also proved

* The 404 handling from §1.1 works against the real API: closed listings parse, and the closure dates and reasons render correctly.
* The theme-author coercion from §1.2 holds for real theme records.
* No PHP notices, warnings or deprecations appeared in any admin screen across the full 47-item render.

### 7.4 — Residual risks added in 1.0.1

| Risk | Severity | Status |
|---|---|---|
| A run that exceeds the budget takes more than one press to finish on very large installs | Info | Accepted and surfaced in the UI, rather than allowed to wedge the plugin |
| Cooldown reduced to 15 s slightly raises the request-amplification ceiling | Low | Accepted; still capped at 100 items per press and 1 press per 15 s |

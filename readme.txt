=== Directory Watch ===
Contributors: deepquest
Tags: security, plugins, themes, monitoring, supply chain
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Alerts you when a plugin or theme you run is closed, removed, renamed or transferred in the WordPress.org directory.

== Description ==

A plugin you installed two years ago can be closed for a security problem tomorrow. It keeps running. It keeps updating from wherever it is hosted. Nothing on your dashboard changes.

Directory Watch is the missing alarm. It records the directory status of every installed plugin and theme, then re-checks it on a schedule and reports any drift.

= What it looks for =

* **Closed** — the directory has disabled downloads for that slug. Usually a security or guideline action.
* **Removed** — the slug is no longer listed at all.
* **New owner** — the author changed. A transfer means new code can ship under a name you already trust, and it is the quietest way for a plugin to change hands.
* **Renamed** — the listing now presents a different name.
* **Listed again** — a slug that had gone missing is back.

Items that were never in the directory — your own custom plugins, premium bundles, internal themes — are recorded as *Not in directory* and never raise an alert. A false "removed" on a private plugin would make the whole alarm worthless.

= How it behaves =

**Nothing is queried on page load.** Checks run from WP-Cron, one batch at a time, with a lock so overlapping runs cannot stack up. Each item is re-checked on the interval you choose (daily by default), and a large site is naturally spread across several passes rather than opening fifty connections at once.

**Only api.wordpress.org is contacted.** The URL is built from a sanitised slug inside the plugin; there is no setting for an endpoint and no user-supplied host, so the plugin cannot be pointed at an internal address.

**Transient failures are not alerts.** A timeout or an HTTP error leaves the last known verdict untouched and records the error next to the row. You get told when the directory actually changes, not when your host hiccups.

**State lives in two options**, is capped at 200 history entries, and is deleted on uninstall along with the scheduled event.

= Who this is for =

Site owners running twenty plugins who cannot track every listing, agencies maintaining client sites, and anyone who wants to know when a dependency changes hands rather than reading about it after the fact.

= Requirements =

* WordPress 5.8 or newer
* PHP 7.4 or newer
* Outbound HTTPS to `api.wordpress.org` (WordPress already uses this host for update checks)

= Developer hooks =

* `dw_settings` — filter the runtime settings array
* `dw_alert_statuses` — choose which status changes trigger an email

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Open **Directory Watch** in the admin menu and press **Check now** for the first pass.

Daily checks continue automatically through WP-Cron. On a site without a real cron runner, WordPress fires the event on the next page load instead.

== Frequently Asked Questions ==

= Does this replace a vulnerability scanner? =

No. It reports directory state, not CVEs. A plugin can be vulnerable and still listed; that is what Wordfence or Patchstack answer. Directory Watch answers a different question: did the listing itself change or disappear?

= Why does a plugin of mine show "Not in directory"? =

Because it is not hosted on WordPress.org — a custom build, a premium bundle, or a theme from a marketplace. Those are recorded on the first pass and never alerted on.

= A single-file plugin shows "Not in directory" even though it is listed. Why? =

The directory slug of a single-file plugin is not always its filename — Hello Dolly is `hello.php` locally but `hello-dolly` in the directory. Directory Watch derives the slug from the installed path, so a handful of single-file plugins are tracked under the wrong key and appear as *Not in directory*. It errs towards silence: you get no false alert, and multi-file plugins (the vast majority) are unaffected.

= Will it slow my site down? =

No. Checks happen in cron, never on a front-end request. One batch is capped at 25 items by default and finishes in a second or two.

= What happens if the directory API is unreachable? =

The last known verdict is kept and the error is shown next to the row. No alert is raised, so a network problem never looks like a closed plugin.

= Does it phone home anywhere else? =

No. There is no account, no telemetry and no second service. Only the public WordPress.org directory API is queried, and only for the slugs you already have installed.

== Screenshots ==

1. The inventory: every installed plugin and theme with its directory status.
2. Settings: check interval, batch size and email alerts.

== Changelog ==

= 1.0.1 =
* Batch runs now stop on a 20 second time budget instead of being killed mid-request by the host, which could leave the run lock and the manual-check cooldown stuck.
* Manual check reports how many items are still queued so a large site can be finished over several presses.
* Manual-check cooldown reduced to 15 seconds.
* Uninstall now also removes the manual-check cooldown transient.

= 1.0.0 =
* First release.
* Watches installed plugins and themes against the WordPress.org directory.
* Detects closed, removed, transferred, renamed and re-listed slugs.
* Batched WP-Cron checks with a concurrency lock and a size-capped API client.
* Optional email alerts for high-signal changes.
* Uninstall removes options and the scheduled event on every site of a multisite network.

== Upgrade Notice ==

= 1.0.1 =
Stops a batch from being cut off by the host time limit on large installs.

= 1.0.0 =
First release.

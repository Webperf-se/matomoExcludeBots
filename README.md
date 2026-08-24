# Matomo ExcludeBots Plugin

## Description

Excludes fake visits from **stealth headless-browser bots** that Matomo's
built-in bot detection and the TrackingSpamPrevention plugin cannot catch.

Since mid-2025 many Matomo (and GA4) users see waves of fake traffic with
this signature:

- Sudden spike of visits, almost always **exactly one pageview per visit**
- Visit duration of **0 seconds**, bounce rate above 90 %
- Claims to come **from Google search** (or direct entry)
- Geographically spread across the whole globe via rotating
  residential and small-hosting proxies
- Fingerprint: genuine **Chrome on desktop Linux** (`X11; Linux x86_64`)
  reporting a **1920x1080** screen – the default viewport of headless
  Chrome automation
- Hits a handful of specific pages over and over (typical for
  SERP-rank-checker and CTR-manipulation bots)

These bots run real Chrome in stealth headless mode, so they execute the
JavaScript tracker and present a normal user agent. IP-based blocking
does not work either: the proxy pools include residential ISP addresses
and small hosting providers that are not on cloud-provider blocklists.

This plugin hooks into `Tracker.isExcludedVisit` and drops tracking
requests matching the full fingerprint (all conditions must match):

1. User agent contains `X11; Linux x86_64`
2. Desktop Chrome (not Mobile, and declared bots are untouched –
   Matomo already handles those)
3. Screen resolution is exactly `1920x1080`
4. Referrer is empty or contains `google.` (the faked "organic" entry)

The only real visitors this can exclude are humans on desktop Linux, in
Chrome, at exactly 1080p, arriving via Google or directly – for most
sites a negligible share, and a trade-off you control (see FAQ).

Exclusion happens at tracking time only: nothing is stored, historical
data is not modified, and reports stay untouched.

## Installation

Copy the plugin into your Matomo installation and activate it:

```
matomo/plugins/ExcludeBots/
├── ExcludeBots.php
└── plugin.json
```

Then activate under *Administration → Plugins*, or via the console:

```
./console plugin:activate ExcludeBots
```

## Configuration (optional)

Defaults match the bot wave described above. Override them in an
`[ExcludeBots]` section in `config/config.ini.php`:

```ini
[ExcludeBots]
; Substring that must appear in the user agent
ua_contains = "X11; Linux x86_64"
; Comma-separated list of screen resolutions that trigger exclusion
resolutions = "1920x1080"
; 1 = only exclude when referrer is empty or contains "google."
; 0 = exclude on user agent + resolution alone
referrer_google_or_empty = 1
```

## FAQ

**How do I know if I am affected?**

Check *Visitors → Overview* for a sudden jump in visits combined with a
collapse of pages-per-visit towards 1.0 and a bounce rate above 90 %.
Then open a day in the Visits Log and look for the fingerprint: Chrome
on GNU/Linux, 1920x1080, 0 s visits, "from Google", from countries that
do not match your audience.

**Will this exclude real visitors?**

Only desktop-Linux Chrome users at exactly 1920x1080 arriving via Google
or directly. Check your own pre-spike share of that segment first; for
most sites it is well under 1 % of traffic. You can view what is being
excluded by temporarily deactivating the plugin and using a segment
instead: `resolution==1920x1080;operatingSystemName==GNU/Linux;browserName==Chrome`.

**Why doesn't TrackingSpamPrevention stop these bots?**

Its cloud-IP blocklists cover the large cloud providers, while these
bots exit through residential ISPs and small hosting companies. Its
headless-browser detection relies on the browser revealing itself
(e.g. a `HeadlessChrome` user agent), which stealth headless setups
avoid. This plugin complements it – keep both enabled.

**Does it clean up historical data?**

No, the plugin only affects new tracking requests. Already-tracked bot
visits have to be removed in three steps, because Matomo stores both the
raw visits and reports aggregated from them:

1. **Delete the raw visits** under *Administration → Privacy → GDPR
   Tools → Search for data subjects*. Select the affected site and add
   filters matching the fingerprint, for example *Operating system =
   GNU/Linux*, *Resolution = 1920x1080* and *Browser = Chrome*. Search,
   tick *select all*, and delete. The visit list is paginated and
   *select all* only covers the rows on screen, so repeat the
   search-and-delete until no matches remain. Note that the search
   cannot be limited to a date range – it matches all retained history.

   **Bulk alternative (SQL, thousands of visits).** For large waves the
   GDPR tool gets tedious, and deleting directly in the database is
   faster and can be date-bounded.

   > ⚠️ **Be careful.** These statements permanently delete raw data
   > and cannot be undone. Take a database backup first (at minimum
   > `mysqldump` of the `log_visit` and `log_link_visit_action`
   > tables), adjust the `matomo_` table prefix to your installation,
   > and set your own site ID and date range. Always run the `SELECT
   > COUNT(*)` first and sanity-check the number against the spike in
   > *Visitors → Overview* before running any `DELETE`.

   ```sql
   -- 1. Count first – does this match the size of your bot wave?
   SELECT COUNT(*) FROM matomo_log_visit
   WHERE idsite = 8
     AND visit_first_action_time >= '2026-07-03 00:00:00'
     AND config_os = 'LIN'            -- GNU/Linux
     AND config_browser_name = 'CH'   -- Chrome
     AND config_resolution = '1920x1080';

   -- 2. Delete the pageviews belonging to those visits
   DELETE llva FROM matomo_log_link_visit_action llva
   JOIN matomo_log_visit lv ON lv.idvisit = llva.idvisit
   WHERE lv.idsite = 8
     AND lv.visit_first_action_time >= '2026-07-03 00:00:00'
     AND lv.config_os = 'LIN'
     AND lv.config_browser_name = 'CH'
     AND lv.config_resolution = '1920x1080';

   -- 3. Delete the visits themselves (same conditions)
   DELETE FROM matomo_log_visit
   WHERE idsite = 8
     AND visit_first_action_time >= '2026-07-03 00:00:00'
     AND config_os = 'LIN'
     AND config_browser_name = 'CH'
     AND config_resolution = '1920x1080';
   ```

   `LIN` and `CH` are Matomo's internal codes for GNU/Linux and Chrome.
   If the bots triggered goals on your site, also clean
   `log_conversion` the same way; for pure pageview bots it is empty.
   Whichever way you delete, continue with steps 2–3 below – reports
   are aggregated separately and do not update by themselves.
2. **Invalidate the reports** for the polluted date range, so Matomo
   knows the aggregates no longer match the raw data:

   ```
   ./console core:invalidate-report-data --dates=2026-06-01,2026-06-30 --sites=8
   ```

   Adjust dates and site ID. If you prefer a UI, Matomo's own
   [InvalidateReports](https://plugins.matomo.org/InvalidateReports)
   plugin adds this under *Administration*.
3. **Re-archive.** If you run the recommended `core:archive` cron, the
   invalidated ranges are rebuilt on its next run. To force it
   immediately:

   ```
   ./console core:archive --force-idsites=8
   ```

Afterwards, the affected days show bot-free numbers everywhere,
including in period totals (weeks, months, year).

**How can I verify it works?**

Enable tracker debug output (`[Tracker] debug = 1` in config, or
`&debug=1` on a tracking request if debug on demand is enabled) and send
a request with the bot fingerprint – the response will contain
`ExcludeBots: request matched headless-bot fingerprint`. The plugin's
logic also has a standalone test suite: `php tests/standalone.php`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v3 or later. See [LICENSE](LICENSE).

## Support

Please report issues at
[github.com/Webperf-se/matomoExcludeBots/issues](https://github.com/Webperf-se/matomoExcludeBots/issues).
Built by [webperf.se](https://webperf.se) after being hit by this exact
bot wave.

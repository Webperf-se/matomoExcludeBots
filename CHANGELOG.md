# Changelog

## 1.0.3

- Corrected the repository URLs in `plugin.json`, `README.md` and
  `ExcludeBots.php` to point at `Webperf-se/matomoExcludeBots`, the
  repository the plugin actually lives in.

## 1.0.2

- Relicensed from MIT to GPL v3 or later, matching Matomo core and the
  Marketplace's preferred license.

## 1.0.1

- Documentation: step-by-step cleanup of already-tracked bot visits
  (GDPR Tools, report invalidation, re-archiving) including a cautious
  SQL alternative for bulk deletion.

## 1.0.0

- Initial release.
- Excludes tracking requests matching the stealth headless-bot
  fingerprint: desktop Chrome on Linux + 1920x1080 + Google/empty
  referrer.
- Fingerprint configurable via an `[ExcludeBots]` section in
  `config.ini.php` (`ua_contains`, `resolutions`,
  `referrer_google_or_empty`).
- Standalone test suite runnable without a Matomo installation.

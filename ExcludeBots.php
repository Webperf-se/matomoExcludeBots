<?php

/**
 * ExcludeBots – a Matomo plugin that excludes fake visits from stealth
 * headless-browser bots at tracking time.
 *
 * Targets a widespread bot pattern that evades Matomo's built-in bot
 * detection and the TrackingSpamPrevention plugin: real (headless)
 * Chrome on desktop Linux, always reporting a 1920x1080 screen, arriving
 * through rotating residential/small-hosting proxies with a claimed
 * Google referrer (or none) and exactly one pageview per visit.
 *
 * All fingerprint conditions must match for a request to be excluded,
 * which keeps false positives to genuine Linux desktop Chrome users at
 * exactly 1080p arriving from Google or directly.
 *
 * @link https://github.com/Webperf-se/matomoExcludeBots
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\ExcludeBots;

use Piwik\Common;
use Piwik\Config;
use Piwik\Tracker\Request;

class ExcludeBots extends \Piwik\Plugin
{
    private const DEFAULTS = [
        // Substring that must appear in the User-Agent (desktop Linux).
        'ua_contains' => 'X11; Linux x86_64',
        // Comma-separated screen resolutions that trigger exclusion.
        // 1920x1080 is the default viewport of headless Chrome setups.
        'resolutions' => '1920x1080',
        // 1 = only exclude when the referrer is empty or contains
        // "google." (the bots fake organic Google traffic). Set to 0 to
        // exclude on UA + resolution alone.
        'referrer_google_or_empty' => 1,
    ];

    public function registerEvents()
    {
        return [
            'Tracker.isExcludedVisit' => 'isExcludedVisit',
        ];
    }

    /**
     * @param bool $excluded Set to true to exclude the tracking request.
     * @param Request $request The tracking request being processed.
     */
    public function isExcludedVisit(&$excluded, Request $request)
    {
        if ($excluded) {
            return; // already excluded by another rule
        }

        $settings = $this->getSettings();

        $ua = (string) $request->getUserAgent();
        if ($settings['ua_contains'] !== ''
            && strpos($ua, $settings['ua_contains']) === false
        ) {
            return;
        }

        // Desktop Chrome only – declared bots ("HeadlessChrome", GPTBot
        // etc.) are already handled by Matomo itself, and mobile browsers
        // never match this bot wave.
        if (strpos($ua, 'Chrome/') === false || strpos($ua, 'Mobile') !== false) {
            return;
        }

        $resolutions = array_filter(array_map('trim', explode(',', $settings['resolutions'])));
        if (!in_array((string) $request->getParam('res'), $resolutions, true)) {
            return;
        }

        if ($settings['referrer_google_or_empty']) {
            $referrer = (string) $request->getParam('urlref');
            if ($referrer !== '' && stripos($referrer, 'google.') === false) {
                return;
            }
        }

        $excluded = true;
        Common::printDebug('ExcludeBots: request matched headless-bot fingerprint');
    }

    /**
     * Defaults, overridable via an [ExcludeBots] section in config.ini.php.
     */
    private function getSettings(): array
    {
        $overrides = [];
        try {
            $section = Config::getInstance()->ExcludeBots;
            if (is_array($section)) {
                $overrides = $section;
            }
        } catch (\Throwable $e) {
            // config not available (e.g. some tracker contexts) – use defaults
        }

        return array_merge(self::DEFAULTS, $overrides);
    }
}

<?php

namespace App\Enums;

/**
 * Detect first, enforce once trusted. The 8G ruleset has a documented
 * false-positive history (phpinfo, a forum plugin's own request path, a
 * WordPress media-search edge case) even in the author's current release —
 * "block from the first request" would be the wrong default for something
 * with that track record.
 */
enum WafMode: string
{
    /** Matches are logged to the site's own waf-detect.log, nothing is blocked. */
    case Detect = 'detect';

    /** A match returns 403 (or 405 for the method check). */
    case Enforce = 'enforce';

    public function title(): string
    {
        return __('app_firewall.waf.modes.'.$this->value);
    }
}

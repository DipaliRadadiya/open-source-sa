<?php

namespace App\Enums;

/**
 * The 8G ruleset split into six independently switchable groups, matching
 * how GridPane's own production port of this same ruleset works — one
 * blunt on/off switch means a single false positive (a forum plugin's
 * request path, `phpinfo()`, a WordPress media-library search edge case —
 * all documented) forces the site owner to give up every category just to
 * fix one. Six toggles let them turn off only the one causing trouble.
 */
enum WafCategory: string
{
    case QueryString = 'query_string';
    case RequestUri = 'request_uri';
    case UserAgent = 'user_agent';
    case Referrer = 'referrer';
    case Cookie = 'cookie';
    case Method = 'method';

    public function title(): string
    {
        return __('app_firewall.waf.categories.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}

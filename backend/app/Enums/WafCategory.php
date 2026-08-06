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
     * One sentence saying what this category actually inspects.
     *
     * The titles are deliberately plain ("Bad cookies"), which reads well in a
     * list and says nothing on its own — nobody can decide whether to switch
     * off "Bad cookies" from those two words. The description is what makes
     * the toggle an informed choice rather than a coin flip, and turning one
     * off to fix a false positive is the documented normal use of this screen.
     */
    public function description(): string
    {
        return __('app_firewall.waf.category_descriptions.'.$this->value);
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

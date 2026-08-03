<?php

namespace App\Enums;

/**
 * What a name attached to an application actually does.
 *
 * The distinction is not cosmetic. An alias serves the same content under a
 * second name, which search engines index as a separate site and split the
 * ranking between — Plesk documents this as the reason their aliases offer a
 * 301 option. A redirect keeps the authority on one name.
 */
enum DomainType: string
{
    /** The canonical name. Exactly one per application. */
    case Primary = 'primary';

    /** Serves the same site under another name. */
    case Alias = 'alias';

    /** Sends a 301 (or 302) elsewhere and serves nothing itself. */
    case Redirect = 'redirect';

    public function label(): string
    {
        return __('application.domain_type.'.$this->value);
    }
}

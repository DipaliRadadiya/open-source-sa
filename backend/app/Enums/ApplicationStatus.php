<?php

namespace App\Enums;

/**
 * Where an application is in its life.
 *
 * P1 only ever produces `pending`: the record exists, nothing has been written
 * to the server. That distinction has to survive into the UI — a user whose
 * app says "active" when no files exist would file a bug against their DNS.
 */
enum ApplicationStatus: string
{
    /** Saved, but nothing has been provisioned yet. */
    case Pending = 'pending';

    /** Provisioning is running (P2+). */
    case Provisioning = 'provisioning';

    /** Files, config and service are in place. */
    case Active = 'active';

    /** Provisioning failed; the reference points at the server-ops log. */
    case Failed = 'failed';

    public function label(): string
    {
        return __('application.status.'.$this->value);
    }
}

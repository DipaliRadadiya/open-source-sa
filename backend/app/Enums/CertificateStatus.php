<?php

namespace App\Enums;

/**
 * Where a certificate is, from the screen's point of view.
 *
 * `Expired` is not a stored state — it is `expires_at` in the past. Storing it
 * would need something to notice the moment it happened, and until that ran the
 * database would be confidently wrong.
 */
enum CertificateStatus: string
{
    /** The row exists, nothing has been requested yet. */
    case Pending = 'pending';

    /** certbot is running. Can last a couple of minutes. */
    case Issuing = 'issuing';

    /** On disk and referenced by the vhost. */
    case Active = 'active';

    /** The last attempt failed; `reason` says how. */
    case Failed = 'failed';
}

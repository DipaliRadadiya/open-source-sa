<?php

namespace App\Exceptions\Server\Storage;

use RuntimeException;

/**
 * An FTPS server presented a certificate other than the one recorded, or none
 * that could be read (FTP-01). Carries the `storage.test.*` key that says
 * which, so a backup that stops here reports why instead of "unreachable".
 */
class FtpsCertificateException extends RuntimeException
{
    public function __construct(public readonly string $i18nKey)
    {
        parent::__construct($i18nKey);
    }
}

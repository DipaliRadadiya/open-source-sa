<?php

namespace App\Services\Server\Docker\Images;

use RuntimeException;

/**
 * A registry could not give us the image, and why.
 *
 * One type with a reason rather than a class per answer, because callers sort
 * the reasons into exactly two piles: an answer about the image ("no such
 * image", "private") that the user acts on, and "we could not ask" — which is
 * not a verdict on the image at all and must never be shown as one. A typo and
 * a dead network are different sentences.
 */
class ImageLookupException extends RuntimeException
{
    /** The repository does not exist (or is private and we hold no credential). */
    public const NOT_FOUND = 'not_found';

    /** The repository exists; this tag or digest does not. */
    public const TAG_NOT_FOUND = 'tag_not_found';

    /** We sent the stored credential and the registry refused it. */
    public const CREDENTIAL_REJECTED = 'credential_rejected';

    /** Network failure, timeout, or a 5xx: we could not ask. */
    public const UNREACHABLE = 'unreachable';

    /** 429 — Docker Hub's anonymous pull limit, usually. */
    public const RATE_LIMITED = 'rate_limited';

    /** The registry host is one the panel must never connect to. */
    public const BLOCKED_HOST = 'blocked_host';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($reason.($detail !== '' ? ': '.$detail : ''));
    }

    /**
     * Is this an answer about the image, rather than a failure to ask?
     */
    public function isAboutTheImage(): bool
    {
        return in_array($this->reason, [self::NOT_FOUND, self::TAG_NOT_FOUND, self::CREDENTIAL_REJECTED], true);
    }
}

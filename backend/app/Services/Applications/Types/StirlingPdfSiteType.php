<?php

namespace App\Services\Applications\Types;

/**
 * Stirling PDF — split, merge, sign and convert PDFs in the browser.
 *
 * The one volume is for OCR language data, which is downloaded on demand and is
 * slow to fetch again. Documents themselves are never stored: they are processed
 * and returned, which is the point of the tool.
 *
 * No accounts by default, so this is a card worth putting behind the panel's own
 * password protection if it is reachable from the internet.
 */
class StirlingPdfSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'stirlingpdf';
    }

    public function category(): string
    {
        return 'productivity';
    }

    public function icon(): string
    {
        return 'stirlingpdf';
    }

    public function containerPort(): int
    {
        return 8080;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/usr/share/tessdata',
        ];
    }
}

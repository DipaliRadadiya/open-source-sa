<?php

namespace App\Services\Server\Settings;

use App\Support\CommandRedactor;

/**
 * The running tail of a security update's output, bounded and redacted.
 *
 * Bounded as `InstallProgress` is: a database write per apt chunk would cost more than the
 * upgrade. The cap keeps the *end* of the output, because that is what is worth reading.
 *
 * Redacted, unlike `InstallProgress`: an upgrade pulls from the box's configured sources, and
 * a private mirror with credentials in its URL is quoted back verbatim when unreachable.
 */
class SecurityUpdateOutput
{
    public const MAX_BYTES = 8192;

    /**
     * How much new output justifies a write.
     *
     * Per chunk would be hundreds of writes; only at the end would show nothing for minutes
     * and lose apt's last words if the run were killed.
     */
    private const FLUSH_BYTES = 1024;

    private string $buffer = '';

    private int $sinceFlush = 0;

    /** Counted as the line goes past, before the tail can drop it. */
    private ?int $packages = null;

    /** True when enough has accumulated to be worth persisting. */
    public function push(string $chunk): bool
    {
        $combined = $this->buffer.$chunk;
        $this->packages ??= $this->countPackages($combined);
        $this->buffer = $this->tail($combined);
        $this->sinceFlush += strlen($chunk);

        if ($this->sinceFlush < self::FLUSH_BYTES) {
            return false;
        }

        $this->sinceFlush = 0;

        return true;
    }

    /**
     * The buffer, every line redacted.
     *
     * Line by line including the last, which may be a fragment: skipping an incomplete line
     * would let a secret cut mid-URL by a chunk boundary survive the redactor.
     */
    public function text(): string
    {
        $lines = array_map(
            static fn (string $line): string => CommandRedactor::line($line),
            explode("\n", $this->buffer),
        );

        return implode("\n", $lines);
    }

    /**
     * How many packages the run said it upgraded, or null if it did not say.
     *
     * Null rather than zero when the line is absent: "did not report" is not "upgraded nothing".
     */
    public function packagesUpgraded(): ?int
    {
        return $this->packages ?? $this->countPackages($this->buffer, requireEndOfLine: false);
    }

    /**
     * The line is printed near the start of a run and the buffer keeps only the last 8 KB, so it
     * is counted as it passes through, once the whole line has arrived (a chunk can end in the
     * middle of the package list).
     */
    private function countPackages(string $text, bool $requireEndOfLine = true): ?int
    {
        $pattern = $requireEndOfLine
            ? '/Packages that will be upgraded:[ \t]*([^\n]*)\n/i'
            : '/Packages that will be upgraded:[ \t]*([^\n]*)/i';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $packages = preg_split('/\s+/', trim($matches[1])) ?: [];
        $packages = array_filter($packages, static fn (string $name): bool => $name !== '');

        return count($packages) > 0 ? count($packages) : null;
    }

    private function tail(string $text): string
    {
        if (strlen($text) <= self::MAX_BYTES) {
            return $text;
        }

        $text = substr($text, -self::MAX_BYTES);

        // Drop the leading fragment: it would look like a complete line.
        $newline = strpos($text, "\n");

        return $newline === false ? $text : substr($text, $newline + 1);
    }
}

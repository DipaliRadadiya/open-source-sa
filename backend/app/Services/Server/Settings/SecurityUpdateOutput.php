<?php

namespace App\Services\Server\Settings;

use App\Support\CommandRedactor;

/**
 * The running tail of a security update's output, bounded and redacted.
 *
 * Bounded for the reason `InstallProgress` is: apt emits hundreds of chunks and
 * a database write each would cost more than the upgrade. The cap keeps the
 * *end* of the output, because the thing worth reading is what it said last.
 *
 * Redacted, unlike `InstallProgress`, which writes apt's bytes through
 * untouched. That is defensible for `apt install php8.4-fpm` — the package name
 * is the whole command. An upgrade pulls from whatever sources the box has
 * configured, and a private mirror with credentials in its URL is quoted back
 * verbatim the moment it cannot be reached. This field is read through the same
 * card as the failed-run excerpt, which is redacted for exactly that reason.
 */
class SecurityUpdateOutput
{
    public const MAX_BYTES = 8192;

    /**
     * How much new output justifies a write.
     *
     * Per-chunk would be hundreds of writes; only-at-the-end would leave the
     * screen watching a spinner with nothing under it for minutes, and would
     * lose apt's last words entirely if the run were killed. A kilobyte is the
     * compromise, and it scales with how talkative the run is rather than with
     * a clock.
     */
    private const FLUSH_BYTES = 1024;

    private string $buffer = '';

    private int $sinceFlush = 0;

    /** True when enough has accumulated to be worth persisting. */
    public function push(string $chunk): bool
    {
        $this->buffer = $this->tail($this->buffer.$chunk);
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
     * Line by line including the last, which may be a fragment: a chunk
     * boundary can land in the middle of the URL being complained about, and
     * skipping the incomplete line is how the one secret in the output survives
     * the redactor.
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
     * unattended-upgrades names them on one line. Null rather than zero when
     * the line is absent: "it did not report" and "it upgraded nothing" are
     * different answers, and only the second is a claim.
     */
    public function packagesUpgraded(): ?int
    {
        if (preg_match('/Packages that will be upgraded:\s*(.+)/i', $this->buffer, $matches) !== 1) {
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

        // Starting mid-line makes the first line look like a complete
        // statement. Drop the fragment.
        $newline = strpos($text, "\n");

        return $newline === false ? $text : substr($text, $newline + 1);
    }
}

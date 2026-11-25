<?php

namespace App\Services\Runtime;

use App\Models\RuntimeInstall;

/**
 * Turns apt's output into a step and a tail, as it arrives.
 *
 * Steps are read out of the output, not invented: a timer-driven bar would keep moving while
 * apt sits blocked on a dpkg lock.
 *
 *   Get:3 http://... php8.4-fpm      -> downloading
 *   Unpacking php8.4-fpm ...         -> unpacking
 *   Setting up php8.4-fpm ...        -> configuring
 *
 * When apt says nothing recognisable the step stays where it was. The raw tail is kept too,
 * because only apt's own words say why an install stopped.
 */
class InstallProgress
{
    /**
     * Kept from the end: the last output before a stop is what explains a failure.
     */
    public const MAX_OUTPUT_BYTES = 8192;

    /**
     * Ordered, so a step never goes backwards (apt interleaves unpacking and setting up).
     *
     * @var array<int, string>
     */
    public const STEPS = ['preparing', 'downloading', 'unpacking', 'configuring'];

    /**
     * The phrases each installer announces its phases with: apt (PHP, extensions, database
     * engines) and fnm (Node).
     *
     * Anchored at line start where the tool allows, so a package *named* "unpacking-tools"
     * cannot move the step. fnm's `Downloading` appears mid-line, so it is matched as a word.
     *
     * @var array<string, string>
     */
    private const MARKERS = [
        'downloading' => '/^\s*Get:\d+\s|\bDownloading\b/mi',
        'unpacking' => '/^\s*(Unpacking|Preparing to unpack)\s|\bExtracting\b/mi',
        'configuring' => '/^\s*Setting up\s|\bInstalling Node\b/mi',
    ];

    private string $buffer = '';

    private string $step = 'preparing';

    public function __construct(private RuntimeInstall $install) {}

    /**
     * Fold one chunk in. Returns true when the step changed, so the caller persists on a
     * transition, not on every chunk.
     */
    public function push(string $chunk): bool
    {
        $this->buffer = $this->tail($this->buffer.$chunk);

        $before = $this->step;

        foreach (self::MARKERS as $step => $pattern) {
            // Later steps win: one chunk can carry both "Unpacking" and "Setting up".
            if (preg_match($pattern, $chunk) === 1 && $this->isAfter($step, $this->step)) {
                $this->step = $step;
            }
        }

        return $this->step !== $before;
    }

    /** Write what we have to the row. */
    public function persist(): void
    {
        $this->install->forceFill([
            'current_step' => $this->step,
            'output' => $this->buffer,
        ])->save();
    }

    /** Flush the output tail without replacing a later domain-specific step. */
    public function persistOutput(): void
    {
        $this->install->forceFill(['output' => $this->buffer])->save();
    }

    public function step(): string
    {
        return $this->step;
    }

    public function output(): string
    {
        return $this->buffer;
    }

    private function isAfter(string $candidate, string $current): bool
    {
        return array_search($candidate, self::STEPS, true) > array_search($current, self::STEPS, true);
    }

    /** Trimmed on a line boundary where possible, so the first line is not cut mid-sentence. */
    private function tail(string $text): string
    {
        if (strlen($text) <= self::MAX_OUTPUT_BYTES) {
            return $text;
        }

        $cut = substr($text, -self::MAX_OUTPUT_BYTES);
        $newline = strpos($cut, "\n");

        return $newline === false ? $cut : substr($cut, $newline + 1);
    }
}

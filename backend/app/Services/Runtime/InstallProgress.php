<?php

namespace App\Services\Runtime;

use App\Models\RuntimeInstall;

/**
 * Turns apt's output into a step and a tail, as it arrives.
 *
 * The steps are **read out of the output**, not invented. That distinction is
 * the whole design: an install is one `apt-get install` call and the panel
 * cannot see inside it, so a bar advancing on a timer would be telling the
 * operator a story nothing is checking — and would keep moving while apt sat
 * blocked on a dpkg lock, which is precisely when someone is watching it.
 *
 * apt does announce its phases, so there is no need to guess:
 *
 *   Get:3 http://... php8.4-fpm      -> downloading
 *   Unpacking php8.4-fpm ...         -> unpacking
 *   Setting up php8.4-fpm ...        -> configuring
 *
 * A step therefore only ever moves because the server said so. When apt says
 * nothing recognisable the step stays where it was, which is the honest
 * answer to "what is it doing" rather than a number that keeps climbing.
 *
 * The raw tail is kept alongside, because the step tells you where it stopped
 * and only apt's own words tell you why.
 */
class InstallProgress
{
    /**
     * Kept from the end, not the start: the last thing apt said before it
     * stopped is the part that explains a failure, and the first 8 KB of a
     * package list explains nothing.
     */
    public const MAX_OUTPUT_BYTES = 8192;

    /**
     * Ordered, so a step can never go backwards — apt interleaves unpacking
     * and setting up across packages, and a display that flickered between
     * them would look broken.
     *
     * @var array<int, string>
     */
    public const STEPS = ['preparing', 'downloading', 'unpacking', 'configuring'];

    /**
     * First match wins per line, cheapest first.
     *
     * @var array<string, string>
     */
    private const MARKERS = [
        'downloading' => '/^\s*Get:\d+\s/mi',
        'unpacking' => '/^\s*(Unpacking|Preparing to unpack)\s/mi',
        'configuring' => '/^\s*Setting up\s/mi',
    ];

    private string $buffer = '';

    private string $step = 'preparing';

    public function __construct(private RuntimeInstall $install) {}

    /**
     * Fold one chunk in. Returns true when the step changed, so the caller can
     * persist on a transition rather than on every read — apt emits hundreds
     * of chunks and each one is a database write otherwise.
     */
    public function push(string $chunk): bool
    {
        $this->buffer = $this->tail($this->buffer.$chunk);

        $before = $this->step;

        foreach (self::MARKERS as $step => $pattern) {
            // Later steps win: one chunk can carry both "Unpacking" and
            // "Setting up", and the second is the truer answer.
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

    /**
     * Trimmed on a line boundary where possible, so the first line shown is
     * not half a sentence.
     */
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

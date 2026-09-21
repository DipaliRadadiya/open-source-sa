<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use Throwable;

/**
 * Turns a driver's raw failure into one of the panel's stable categories.
 *
 * **The reason this is a shared walk rather than three `if` ladders: Flysystem
 * never hands back the exception that actually went wrong.** Every adapter
 * wraps it — a rejected SFTP host key arrives as
 * `UnableToWriteFile: Unable to write file at location: … The authenticity of
 * host can't be established.` with the real
 * `UnableToEstablishAuthenticityOfHost` one level down in `getPrevious()`.
 * Each driver's `instanceof` checks were reading the wrapper, so none of them
 * could ever match, and every typed failure fell through to the catch-all.
 *
 * Measured against a live SFTP server, not inferred: a changed host key was
 * reported as **"could not be reached"**, which sends an operator to check
 * their firewall while a possible interception goes unmentioned. The unit test
 * covering it passed throughout, because its fake threw the inner exception
 * directly — a shape the real adapter never produces.
 *
 * **Types are asked about the whole chain before any message is.** A type is a
 * statement; a message match is a guess, and the wrapper's message contains
 * the inner one, so a string rule on the outer link would otherwise pre-empt a
 * precise type on the inner. Both passes walk outward-in, so the most specific
 * answer available wins over the most general.
 */
trait ClassifiesFailures
{
    public function classify(Throwable $e): string
    {
        foreach ($this->chainOf($e) as $link) {
            $category = $this->categoryForType($link);

            if ($category !== null) {
                return $category;
            }
        }

        foreach ($this->chainOf($e) as $link) {
            $category = $this->categoryForMessage(strtolower($link->getMessage()));

            if ($category !== null) {
                return $category;
            }

            $category = $this->sharedCategoryForMessage(strtolower($link->getMessage()));

            if ($category !== null) {
                return $category;
            }
        }

        // Unreachable is the honest default: something failed and nothing in
        // the chain said what. It must stay the *last* answer rather than an
        // early one — that is the bug this trait exists to stop.
        return 'storage.test.unreachable';
    }

    /**
     * Rules that belong to no single driver.
     *
     * A stalled transfer is not an S3 problem or a Drive problem — it is cURL
     * reporting that the bytes stopped, and every driver that speaks HTTP can
     * produce it. Put in one place rather than copied into each
     * `categoryForMessage()`, because a rule duplicated four times is a rule
     * that will be updated in three.
     *
     * Checked *after* the driver's own pass, so a provider that has a more
     * specific name for the same condition keeps it.
     */
    private function sharedCategoryForMessage(string $message): ?string
    {
        // cURL error 28 with this wording is specifically the low-speed abort
        // configured in GoogleHttpClient — the transfer moved nothing for the
        // stall window — and not an ordinary timeout. Saying "unreachable"
        // here would send an operator to check a network that is working
        // fine; the connection was established and then went quiet.
        if (str_contains($message, 'operation too slow')
            || str_contains($message, 'less than 1 bytes/sec')) {
            return 'storage.upload.stalled';
        }

        return null;
    }

    /**
     * The exception and everything it wrapped, outermost first.
     *
     * @return iterable<Throwable>
     */
    private function chainOf(Throwable $e): iterable
    {
        $seen = 0;

        // Bounded: a cyclic `previous` chain is malformed but possible, and a
        // classifier is not the place to hang the queue worker.
        while ($e !== null && $seen < 10) {
            yield $e;

            $e = $e->getPrevious();
            $seen++;
        }
    }

    /**
     * A category decided by what the exception *is*, or null to defer.
     */
    abstract protected function categoryForType(Throwable $e): ?string;

    /**
     * A category decided by what the exception *says*, or null to defer.
     * The message arrives already lowercased.
     */
    abstract protected function categoryForMessage(string $message): ?string;
}

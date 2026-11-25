<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use Throwable;

/**
 * Turns a driver's raw failure into one of the panel's stable categories.
 *
 * **Flysystem never hands back the exception that actually went wrong:** every adapter wraps it
 * (a rejected SFTP host key arrives as `UnableToWriteFile`, with the real
 * `UnableToEstablishAuthenticityOfHost` in `getPrevious()`). So the whole chain is walked; an
 * `instanceof` on the outer exception alone never matches, and a changed host key would be
 * reported as "could not be reached".
 *
 * **Types are asked about the whole chain before any message is.** A type is a statement, a
 * message match is a guess, and the wrapper's message contains the inner one.
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

        // The default when nothing in the chain said what failed; it must stay the last answer.
        return 'storage.test.unreachable';
    }

    /**
     * Rules that belong to no single driver.
     *
     * A stalled transfer is cURL reporting that the bytes stopped, which any HTTP driver can
     * produce. Checked after the driver's own pass, so a more specific driver rule wins.
     */
    private function sharedCategoryForMessage(string $message): ?string
    {
        // cURL error 28 with this wording is the low-speed abort configured in GoogleHttpClient
        // (connected, then went quiet), not an ordinary timeout.
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

        // Bounded so a cyclic `previous` chain cannot hang the queue worker.
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

<?php

namespace App\Services\Server\Certificates;

use App\Exceptions\Server\Application\CertificateBusyException;
use App\Models\Application;
use App\Services\Server\AccountLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One certificate operation per lineage name at a time: issuing a site's
 * certificate, uploading or removing one, and deleting the site.
 *
 * Without it a site delete races an issuance: `certbot delete` loses to certbot's own process
 * lock, the site is removed, and the issuance then creates a lineage for a site that no longer
 * exists (renewing forever) and crashes reading it.
 *
 * Keyed by the name the job issues under, the site's slug. Two live sites never share one; a
 * later site that reuses a deleted site's slug does, which lets the job decide safely whether a
 * lineage it just made still has an owner.
 *
 * The panel's half of the defence, as {@see AccountLock} is for accounts. certbot's own
 * "Another instance of Certbot is already running" (renew timer, a hand-run certbot) is the
 * other half, covered by ServerOps' transient retry.
 */
class CertificateLock
{
    public function name(Application $application): string
    {
        $slug = (string) $application->slug;

        // Never an empty key: a blank slug would make every such site share one.
        return $slug !== '' ? $slug : 'application-'.$application->id;
    }

    public function lock(string $name): Lock
    {
        return Cache::lock('certificate:'.$name, $this->ttl());
    }

    /**
     * Held by a site delete from before its first change until its row is gone. An issuance in
     * progress is waited for, briefly; past that the delete answers "busy" with nothing changed.
     *
     * @throws CertificateBusyException
     */
    public function forDelete(Application $application): Lock
    {
        return $this->waitFor($application, 'application', 'site delete waited out a certificate operation');
    }

    /**
     * Held by an upload or a removal of the site's certificate for its whole run. Both revoke
     * the Let's Encrypt lineage, which loses to certbot's own lock mid-issuance; an issuance
     * that starts meanwhile finds the lock taken and requeues.
     *
     * @throws CertificateBusyException
     */
    public function forChange(Application $application): Lock
    {
        return $this->waitFor($application, 'certificate', 'certificate change waited out a certificate operation');
    }

    /**
     * @throws CertificateBusyException
     */
    private function waitFor(Application $application, string $feature, string $message): Lock
    {
        $name = $this->name($application);
        $lock = $this->lock($name);
        $wait = max(1, (int) config('server.certificates.lock.delete_wait', 20));

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->warning($message, [
                'feature' => $feature,
                'op' => 'certificate_lock_busy',
                'application' => $application->id,
                'lineage' => $name,
                'waited_seconds' => $wait,
                'reference' => $reference,
            ]);

            throw new CertificateBusyException($reference, busy: true);
        }

        return $lock;
    }

    /**
     * Taken by the issuing job without waiting, so a worker does not burn the job's timeout.
     * Null when held: the job requeues itself.
     */
    public function forIssue(string $name): ?Lock
    {
        $lock = $this->lock($name);

        return $lock->get() ? $lock : null;
    }

    /**
     * Longer than anything that holds it (issuing job timeout 600s, delete behind a 300s proxy
     * timeout), so it never expires under a live holder. Only a dead holder waits this out.
     */
    public function ttl(): int
    {
        return max(1, (int) config('server.certificates.lock.ttl', 900));
    }
}

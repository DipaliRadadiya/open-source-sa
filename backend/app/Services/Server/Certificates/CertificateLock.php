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
 * certificate, and deleting the site.
 *
 * Without it the two raced on a real server (sync-deletion QA, 2026-10-21):
 * the delete's `certbot delete` lost to certbot's own process lock while the
 * issuance was still talking to Let's Encrypt, the delete went on and removed
 * the site, and the issuance then created a lineage for a site that no longer
 * existed — renewing forever — and crashed reading it.
 *
 * Keyed by the name the job issues under, the site's slug. Two live sites
 * never share one, so an unrelated site is never held up; a later site that
 * reuses a deleted site's slug shares the key, which is what lets the job
 * decide safely whether a lineage it just made still has an owner.
 *
 * The panel's half of the defence, as {@see AccountLock}
 * is for accounts. certbot's own "Another instance of Certbot is already
 * running" — the renew timer, a hand-run certbot — is the other half, ridden
 * over by ServerOps' transient retry.
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
     * Held by a site delete from before its first change until its row is
     * gone. An issuance in progress is waited for, briefly; past that the
     * delete answers "busy, try again" with nothing changed.
     *
     * @throws CertificateBusyException
     */
    public function forDelete(Application $application): Lock
    {
        $name = $this->name($application);
        $lock = $this->lock($name);
        $wait = max(1, (int) config('server.certificates.lock.delete_wait', 20));

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->warning('site delete waited out a certificate operation', [
                'feature' => 'application',
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
     * Taken by the issuing job without waiting: a worker blocked on a lock
     * spends the job's own timeout doing nothing. Null when it is held — the
     * job puts itself back on the queue instead.
     */
    public function forIssue(string $name): ?Lock
    {
        $lock = $this->lock($name);

        return $lock->get() ? $lock : null;
    }

    /**
     * Longer than anything that holds it, so it never expires under a live
     * holder: the issuing job is killed at its own timeout (600s), and a
     * delete is a request behind a 300s proxy timeout. Only a holder that died
     * without releasing waits this out.
     */
    public function ttl(): int
    {
        return max(1, (int) config('server.certificates.lock.ttl', 900));
    }
}

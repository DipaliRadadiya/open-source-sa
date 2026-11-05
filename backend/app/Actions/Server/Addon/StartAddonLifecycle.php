<?php

namespace App\Actions\Server\Addon;

use App\Exceptions\Addons\AddonException;
use App\Jobs\RunAddonLifecycle;
use App\Models\AddonRun;
use App\Services\Addons\AddonCli;
use App\Services\Addons\AddonPackage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Queue an install, update or removal of an addon, and answer with the run
 * Central polls.
 *
 * One at a time per addon: a removal halfway through an update, or two
 * updates writing the same binary, leave nothing anyone could roll back to.
 */
class StartAddonLifecycle
{
    /**
     * @param  array<string, mixed>  $arguments  for an install: version, url, sha256, signature, retention_days
     *
     * @throws AddonException
     */
    public function execute(AddonCli $addon, string $command, array $arguments = []): AddonRun
    {
        // Refused now rather than queued to fail: with no key, no install can
        // ever succeed on this server.
        if ($command === AddonRun::INSTALL && AddonPackage::signatureRequired() && AddonPackage::signingKey() === null) {
            throw AddonException::of('addon_signing_key_missing', $addon->label());
        }

        // Held only for the check-and-write, so two requests cannot both find
        // nothing running and both queue one.
        $lock = Cache::lock('addon-lifecycle:'.$addon->name(), 10);

        if (! $lock->get()) {
            throw AddonException::of('addon_busy', $addon->label());
        }

        try {
            if (AddonRun::lifecycleInProgress($addon->name()) !== null) {
                throw AddonException::of('addon_busy', $addon->label());
            }

            $run = AddonRun::create([
                'addon' => $addon->name(),
                'command' => $command,
                'status' => AddonRun::QUEUED,
                'arguments' => $arguments,
            ]);
        } finally {
            $lock->release();
        }

        // After the lock is released: on the `sync` driver the job runs here.
        try {
            RunAddonLifecycle::dispatch($run->id, Auth::id())->onQueue('default');
        } catch (Throwable $e) {
            // The queue would not take it: close the run, or it reads as
            // running and refuses the next try.
            $run->update(['status' => AddonRun::FAILED, 'http_status' => 500, 'result' => ['code' => 'addon_run_failed', 'message' => __('errors/addons.run_failed')], 'finished_at' => now()]);

            throw $e;
        }

        return $run->refresh();
    }
}

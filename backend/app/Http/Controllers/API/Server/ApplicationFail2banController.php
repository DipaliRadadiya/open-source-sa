<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CustomFail2banRequest;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationFail2banManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Per-application fail2ban — the commercial-style raw INI variant.
 *
 * The previous version stored structured fields (maxretry/findtime/bantime)
 * and rendered them into a server-managed drop-in on every sync. That
 * approach was easy to read in the database and impossible to support:
 * fail2ban's jail and filter files are arbitrary INI, and any feature
 * worth exposing (custom regex, additional action, multiple logpaths) needs
 * the raw form. This controller stores raw INI for both halves and writes
 * them as-is to /etc/fail2ban/{jail,filter}.d/sVoss-<slug>.conf.
 *
 * The auto-migrate block in show() is the bridge from the old schema: any
 * application that still has structured values, gets a one-time conversion
 * to equivalent INI on the next read, the structured columns are cleared,
 * and the next deploy drops the structured columns from the schema. New
 * applications never see the structured form.
 */
class ApplicationFail2banController extends Controller
{
    public function show(Application $application, ApplicationFail2banManager $manager): JsonResponse
    {
        $this->migrateFromStructured($application);

        $jailTemplate = $manager->defaultJailContent();
        $filterTemplate = $manager->defaultFilterContent();

        if ($application->fail2ban_jail_content === null) {
            // Brand-new application, never configured: hand the caller the
            // templates it can pre-fill the form with, and report a null
            // "current config" so the UI can render "not yet configured"
            // rather than echoing the template as a saved value.
            return response()->json([
                'fail2ban' => null,
                'jail_template' => $jailTemplate,
                'filter_template' => $filterTemplate,
            ]);
        }

        return response()->json([
            'fail2ban' => [
                'jail_name' => $application->fail2ban_jail_name,
                'jail_content' => $application->fail2ban_jail_content,
                'filter_content' => $application->fail2ban_filter_content,
            ],
            // Echo the saved content as the template, so the form renders the
            // user's last submission unchanged until they edit it.
            'jail_template' => $application->fail2ban_jail_content,
            'filter_template' => $application->fail2ban_filter_content,
        ]);
    }

    /**
     * Validate, test, save, write to disk, reload. The test is the gate:
     * a config that does not parse against fail2ban-client never reaches the
     * live daemon, so a bad submission is contained to a 500 with the
     * fail2ban-client error rather than a taken-down service.
     */
    public function store(
        CustomFail2banRequest $request,
        Application $application,
        ApplicationFail2banManager $manager,
    ): JsonResponse {
        $jailContent = (string) $request->input('jail_config_content');
        $filterContent = (string) $request->input('filter_config_content');

        $test = $manager->testConfigs($application, $jailContent, $filterContent);

        if (! $test['testOk']) {
            return response()->json([
                'testOk' => false,
                'message' => __('fail2ban.test_failed'),
                'output' => $test['output'],
            ], 500);
        }

        $application->fail2ban_jail_name = $manager->jailName($application);
        $application->fail2ban_jail_content = $jailContent;
        $application->fail2ban_filter_content = $filterContent;
        $application->save();

        $manager->enableForApp($application, $jailContent, $filterContent);

        return response()->json([
            'testOk' => true,
            'message' => __('fail2ban.created_successfully'),
        ]);
    }

    public function destroy(Application $application, ApplicationFail2banManager $manager): JsonResponse
    {
        if ($application->fail2ban_jail_content === null) {
            return response()->json([
                'message' => __('fail2ban.already_disabled'),
            ], 500);
        }

        $manager->disableForApp($application);

        $application->fail2ban_jail_name = null;
        $application->fail2ban_jail_content = null;
        $application->fail2ban_filter_content = null;
        $application->save();

        return response()->json([
            'message' => __('fail2ban.disabled_successfully'),
        ]);
    }

    /**
     * One-time migration from the old structured form.
     *
     * Reads maxretry/findtime/bantime (and ignore_ips, dropped from the new
     * model — ignoreips would otherwise have no place to land), renders
     * them into the default INI templates, persists the new columns, and
     * clears the old ones. Idempotent: every guard short-circuits the
     * second call, so a row that has already migrated is a no-op.
     *
     * The Schema::hasColumn guard matters because the migration that drops
     * the old columns is in the same release as this code: on a fresh
     * install, the columns were never created in the first place, and
     * Eloquent would surface their absence as a confusing null rather
     * than the correct "nothing to migrate".
     */
    private function migrateFromStructured(Application $application): void
    {
        if ($application->fail2ban_jail_content !== null) {
            return;
        }

        $hasOldColumns = Schema::hasColumn('applications', 'fail2ban_maxretry')
            || Schema::hasColumn('applications', 'fail2ban_findtime')
            || Schema::hasColumn('applications', 'fail2ban_bantime');

        if (! $hasOldColumns) {
            return;
        }

        // Read raw — the model fillable no longer lists these columns, so
        // $application->fail2ban_maxretry would still resolve via Eloquent's
        // attribute getter, but going through getAttributes() makes the
        // "I'm reading from a soon-to-be-dropped column" intent explicit
        // and removes any ambiguity about what value is being migrated.
        $attributes = $application->getAttributes();
        $maxretry = $attributes['fail2ban_maxretry'] ?? null;
        $findtime = $attributes['fail2ban_findtime'] ?? null;
        $bantime = $attributes['fail2ban_bantime'] ?? null;

        if ($maxretry === null && $findtime === null && $bantime === null) {
            return;
        }

        $defaults = ['maxretry' => 3, 'findtime' => 600, 'bantime' => 3600];

        $slug = (string) ($application->slug ?: $application->domain ?: ('app-'.$application->id));

        $jail = "[sVoss-{$slug}]\n"
            ."enabled  = true\n"
            ."port     = http,https\n"
            ."filter   = sVoss-{$slug}\n"
            ."logpath  = /var/log/nginx/{$slug}.access.log\n"
            .'maxretry = '.((int) ($maxretry ?: $defaults['maxretry']))."\n"
            .'bantime  = '.((int) ($bantime ?: $defaults['bantime']))."\n"
            .'findtime = '.((int) ($findtime ?: $defaults['findtime']))."\n";

        $filter = "[sVoss-{$slug}]\n"
            ."failregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\n"
            ."           ^<HOST> .* \"(POST|PUT|DELETE) .*xmlrpc.php\n"
            ."           ^<HOST> .* \"(POST|PUT|DELETE) .*wp-admin.*\n"
            ."ignoreregex =\n";

        $application->fail2ban_jail_name = "sVoss-{$slug}";
        $application->fail2ban_jail_content = $jail;
        $application->fail2ban_filter_content = $filter;

        // Clear the old values so a subsequent migration (or a future
        // redeploy with the columns back) sees a clean row. forceFill
        // because the columns are no longer in $fillable — they are being
        // dropped, not assigned to, but save() must still see the change
        // for the database update to be emitted.
        $application->forceFill([
            'fail2ban_maxretry' => null,
            'fail2ban_findtime' => null,
            'fail2ban_bantime' => null,
            'fail2ban_ignore_ips' => null,
        ])->save();
    }
}

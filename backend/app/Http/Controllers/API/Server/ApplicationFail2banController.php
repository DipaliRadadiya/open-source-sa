<?php

namespace App\Http\Controllers\API\Server;

use App\Exceptions\Server\Application\Fail2banConfigRejectedException;
use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CustomFail2banRequest;
use App\Http\Requests\Server\Application\DisableFail2banRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationFail2banManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Per-application fail2ban, stored as raw INI.
 *
 * Jail and filter files are arbitrary INI (custom regex, extra actions, multiple logpaths),
 * so both halves are stored raw and written as-is to
 * /etc/fail2ban/{jail,filter}.d/<app-slug>.conf.
 *
 * `show()` converts an application that still has the old structured values
 * (maxretry/findtime/bantime) to INI once, on the next read, and clears them.
 */
class ApplicationFail2banController extends Controller
{
    public function show(Application $application, ApplicationFail2banManager $manager): JsonResponse
    {
        $this->migrateFromStructured($application);

        // Rendered, not raw: the default templates contain `{name}`, `{filter}` and `{logpath}`
        // placeholders, which the write path substitutes, so the form must show the rendered text.
        $templates = $manager->renderConfigs(
            $application,
            $manager->defaultJailContent(),
            $manager->defaultFilterContent(),
        );

        if ($application->fail2ban_jail_content === null) {
            // Never configured: hand over the templates to pre-fill the form, and a null
            // "current config" so the UI shows "not yet configured".
            return response()->json([
                'fail2ban' => null,
                'jail_template' => $templates['jail'],
                'filter_template' => $templates['filter'],
                ...$this->filterScope($application),
            ]);
        }

        // Saved content goes through the same transform, so a pasted-back placeholder shows as
        // what the server will write. Display only: what is stored stays as submitted.
        $saved = $manager->renderConfigs(
            $application,
            (string) $application->fail2ban_jail_content,
            (string) $application->fail2ban_filter_content,
        );

        return response()->json([
            'fail2ban' => [
                'jail_name' => $application->fail2ban_jail_name,
                'jail_content' => $application->fail2ban_jail_content,
                'filter_content' => $application->fail2ban_filter_content,
            ],
            // The saved content as the template, so the form shows the last submission.
            'jail_template' => $saved['jail'],
            'filter_template' => $saved['filter'],
            ...$this->filterScope($application),
        ]);
    }

    /**
     * What the default filter protects: failed WordPress logins and XML-RPC only, so on any
     * other site type it is a jail that never bans. Said rather than guessed per type: a rule
     * must tell a failed login from a good one by the access log, and a guessed rule bans the
     * site's own users.
     *
     * @return array{filter_scope: string, filter_note: ?string}
     */
    private function filterScope(Application $application): array
    {
        if ($application->site_type === 'wordpress') {
            return ['filter_scope' => 'wordpress', 'filter_note' => null];
        }

        return [
            'filter_scope' => 'wordpress_only',
            'filter_note' => __('fail2ban.app_default_filter_wordpress_only'),
        ];
    }

    /**
     * Validate, test, save, write to disk, reload. The test is the gate: a config that does not
     * parse against fail2ban-client never reaches the live daemon.
     */
    public function store(
        CustomFail2banRequest $request,
        Application $application,
        ApplicationFail2banManager $manager,
        ActivityLogger $log,
    ): JsonResponse {
        // Before anything is staged: the config test copies /etc/fail2ban, which would not exist.
        if (! $manager->installed()) {
            throw Fail2banException::notInstalled();
        }

        $jailContent = (string) $request->input('jail_config_content');
        $filterContent = (string) $request->input('filter_config_content');

        $test = $manager->testConfigs($application, $jailContent, $filterContent);

        // 422: the daemon refusing the config is an answer about the input, and names the line.
        if (! $test['testOk']) {
            return response()->json([
                'testOk' => false,
                'message' => __('fail2ban.test_failed'),
                'output' => $test['output'],
            ], 422);
        }

        // Applied first, recorded after: the test passes some configs the daemon then refuses
        // on reload, and saving first would leave the database describing a rolled-back config.
        try {
            $manager->enableForApp($application, $jailContent, $filterContent, $request->safetyCopy());
        } catch (Fail2banConfigRejectedException $e) {
            return response()->json([
                'testOk' => false,
                'message' => __('fail2ban.rejected'),
                'output' => $e->output,
            ], 422);
        }

        $application->fail2ban_jail_name = $manager->jailName($application);
        $application->fail2ban_jail_content = $jailContent;
        $application->fail2ban_filter_content = $filterContent;
        $application->save();

        $log->log('application.fail2ban_enabled', $application, ['name' => $application->name, 'safety_copy' => $request->safetyCopy()]);

        return response()->json([
            'testOk' => true,
            'message' => __('fail2ban.created_successfully'),
        ]);
    }

    public function destroy(DisableFail2banRequest $request, Application $application, ApplicationFail2banManager $manager, ActivityLogger $log): JsonResponse
    {
        if ($application->fail2ban_jail_content === null) {
            return response()->json([
                'message' => __('fail2ban.already_disabled'),
            ], 422);
        }

        // Without fail2ban the jail's files still go (or a reinstall would bring the jail
        // back), but there is no daemon to reload.
        $manager->disableForApp($application, $request->safetyCopy(), reload: $manager->installed());

        $application->fail2ban_jail_name = null;
        $application->fail2ban_jail_content = null;
        $application->fail2ban_filter_content = null;
        $application->save();

        $log->log('application.fail2ban_disabled', $application, ['name' => $application->name, 'safety_copy' => $request->safetyCopy()]);

        return response()->json([
            'message' => __('fail2ban.disabled_successfully'),
        ]);
    }

    /**
     * One-time migration from the old structured form.
     *
     * Reads maxretry/findtime/bantime (ignore_ips is dropped from the new model), renders them
     * into INI, persists the new columns and clears the old ones. Idempotent.
     *
     * The Schema::hasColumn guard matters: on a fresh install the old columns were never
     * created, and their absence would otherwise surface as a confusing null.
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

        // Read raw: the columns are no longer in the model's fillable, and getAttributes() makes
        // it explicit they are being read from a soon-to-be-dropped column.
        $attributes = $application->getAttributes();
        $maxretry = $attributes['fail2ban_maxretry'] ?? null;
        $findtime = $attributes['fail2ban_findtime'] ?? null;
        $bantime = $attributes['fail2ban_bantime'] ?? null;

        if ($maxretry === null && $findtime === null && $bantime === null) {
            return;
        }

        $defaults = ['maxretry' => 3, 'findtime' => 600, 'bantime' => 3600];

        $slug = (string) ($application->slug ?: $application->domain ?: ('app-'.$application->id));

        $jail = "[{$slug}]\n"
            ."enabled  = true\n"
            ."port     = http,https\n"
            ."filter   = {$slug}\n"
            // The placeholder, not a path: the write path substitutes the active web-server
            // driver's log path (OpenLiteSpeed logs inside the site directory). A baked-in nginx
            // path would watch a missing file on OLS and ban nobody.
            ."logpath  = {logpath}\n"
            .'maxretry = '.((int) ($maxretry ?: $defaults['maxretry']))."\n"
            .'bantime  = '.((int) ($bantime ?: $defaults['bantime']))."\n"
            .'findtime = '.((int) ($findtime ?: $defaults['findtime']))."\n";

        // The current default, not a copy of an old one: the old filter banned logged-in admins
        // and lacked the `[Definition]` header, so fail2ban found no failregex in it.
        $filter = app(ApplicationFail2banManager::class)->defaultFilterContent();

        $application->fail2ban_jail_name = $slug;
        $application->fail2ban_jail_content = $jail;
        $application->fail2ban_filter_content = $filter;

        // Clear the old values. forceFill because the columns are no longer in $fillable.
        $application->forceFill([
            'fail2ban_maxretry' => null,
            'fail2ban_findtime' => null,
            'fail2ban_bantime' => null,
            'fail2ban_ignore_ips' => null,
        ])->save();
    }
}

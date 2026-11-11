<?php

namespace App\Services\Server\Applications;

use App\Enums\AiBotPolicy;
use App\Exceptions\Server\Application\BotBlockerOperationException;
use App\Exceptions\Server\WebServer\WebServerReloadFailedException;
use App\Models\Application;
use App\Models\ApplicationBotRule;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Collection;

/**
 * The AI Bot Blocker: one policy column plus a per-site list of additions and
 * exemptions. Changing any of them only ever means re-rendering the vhost
 * with a different (or no) user-agent block.
 *
 * Same apply → test → reload → rollback shape as `BasicAuthManager`,
 * `Waf8GManager` and `ApplicationProvisioner::disable()`/`enable()`, and the
 * rules borrow the WAF's discipline exactly: they are rendered from an
 * in-memory collection and only written to the database once the config test
 * has proved the new state is safe. A failed test must leave the database as
 * it was.
 */
class BotBlockerManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private V7Leftovers $leftovers,
    ) {}

    /**
     * @param  array<int, string>|null  $blocked  null leaves the stored list alone
     * @param  array<int, string>|null  $allowed  null leaves the stored list alone
     */
    public function apply(
        Application $application,
        AiBotPolicy $policy,
        ?array $blocked = null,
        ?array $allowed = null,
    ): void {
        $previous = $application->ai_bot_policy;
        $rulesChange = $blocked !== null || $allowed !== null;

        $pending = $this->pendingRules($application, $blocked, $allowed);

        if ($previous === $policy && ! $this->rulesDiffer($application, $pending)) {
            return;
        }

        $application->ai_bot_policy = $policy;
        $application->setRelation('botRules', $pending);

        // v7's own files for this setting go first: one sits at the name v8
        // writes to, and v8 never writes over a file it did not write. Put
        // back with everything else if the change does not hold.
        $moved = $this->leftovers->remove($application, 'ai_bot_blocker');

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            $this->leftovers->restore($application, $moved);
            $this->restore($application, $previous, $rulesChange);

            throw new BotBlockerOperationException($applied->reference);
        }

        // Refused with v7's files gone: something may still lean on them (an
        // nginx conf.d map another vhost reads), so they go back first and
        // the change is kept if that is enough.
        if ($this->webServers->driver()->test()->failed()
            && ($moved === [] || ! $this->leftoversWereTheProblem($application, $moved))) {
            $this->leftovers->restore($application, $moved);
            $restored = $this->restore($application, $previous, $rulesChange);

            throw new BotBlockerOperationException($restored->reference);
        }

        // Saved either way: the tested config is on disk, and the rows have to
        // say what the disk says. A failed reload is reported at the end.
        $reload = $this->webServers->driver()->reload();

        $application->save();

        if ($moved !== []) {
            app(ActivityLogger::class)->log('application.v7_leftovers_removed', $application, [
                'name' => $application->name,
                'files' => count($moved),
            ]);
        }

        if ($rulesChange) {
            // Replaced wholesale, and only now — see the class docblock.
            $application->botRules()->delete();

            foreach ($pending as $rule) {
                $application->botRules()->create(['type' => $rule->type, 'value' => $rule->value]);
            }
        }

        if ($reload->failed()) {
            throw new WebServerReloadFailedException($reload->reference);
        }
    }

    /**
     * Put v7's files back and ask again: true when that alone makes the
     * config pass, which keeps the change and leaves them in place.
     *
     * @param  array<int, array{from: string, to: string}>  $moved
     */
    private function leftoversWereTheProblem(Application $application, array &$moved): bool
    {
        // Only the ones elsewhere: one at a name the panel now writes would
        // go back over the panel's own file, the test would pass, and the
        // setting would say one thing while v7's old rules did another.
        $elsewhere = $this->leftovers->outsideSiteConf($application, $moved);

        if ($elsewhere === []) {
            return false;
        }

        $this->leftovers->restore($application, $elsewhere);
        $moved = array_values(array_udiff($moved, $elsewhere, fn ($a, $b) => strcmp($a['from'], $b['from'])));

        return $this->webServers->driver()->test()->ok;
    }

    /**
     * Put the previous policy and the stored rules back into the live config.
     * Nothing has been reloaded at either call site, so this is restoring the
     * files to match what the server is still serving.
     */
    private function restore(Application $application, ?AiBotPolicy $previous, bool $rulesChange): ServerOpsResult
    {
        $application->ai_bot_policy = $previous;

        if ($rulesChange) {
            $application->load('botRules');
        }

        return $this->applyVhost($application);
    }

    /**
     * The rule set the new config should be rendered against: the incoming
     * lists where given, the stored ones where not.
     *
     * @param  array<int, string>|null  $blocked
     * @param  array<int, string>|null  $allowed
     * @return Collection<int, ApplicationBotRule>
     */
    private function pendingRules(Application $application, ?array $blocked, ?array $allowed): Collection
    {
        $application->loadMissing('botRules');

        $stored = fn (string $type) => $application->botRules
            ->where('type', $type)
            ->pluck('value')
            ->all();

        $build = fn (string $type, array $values) => collect($values)->map(
            fn (string $value) => new ApplicationBotRule(['type' => $type, 'value' => $value]),
        );

        return $build('block', $blocked ?? $stored('block'))
            ->concat($build('allow', $allowed ?? $stored('allow')))
            ->values();
    }

    /**
     * @param  Collection<int, ApplicationBotRule>  $pending
     */
    private function rulesDiffer(Application $application, Collection $pending): bool
    {
        $application->loadMissing('botRules');

        $key = fn ($rules) => $rules
            ->map(fn ($rule) => $rule->type.':'.mb_strtolower((string) $rule->value))
            ->sort()
            ->values()
            ->all();

        return $key($application->botRules) !== $key($pending);
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }
}

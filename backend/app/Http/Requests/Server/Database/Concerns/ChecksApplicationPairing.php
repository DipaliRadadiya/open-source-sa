<?php

namespace App\Http\Requests\Server\Database\Concerns;

use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\InstallerManager;
use Illuminate\Validation\Validator;

/**
 * The two rules a database and an application must meet to be paired.
 *
 * Shared by attaching an existing database and creating one for an
 * application (FS-C13): only attaching checked them, so `POST /databases`
 * gave a site a second database the attach screen would have refused.
 */
trait ChecksApplicationPairing
{
    /**
     * One database per application, for now.
     *
     * Backups dump every attached database, but staging and cloning both take
     * `Database::where('application_id', …)->first()` — with two attached, the
     * one they copy is insertion order. Refusing here is the difference between
     * that ambiguity being unreachable and being one click away; widening this
     * means teaching those strategies to say which database they mean, not
     * deleting this check.
     */
    private function refuseSecondDatabase(Validator $validator, Application $application, ?int $except = null): void
    {
        $existing = Database::query()
            ->where('application_id', $application->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->first();

        if ($existing !== null) {
            $validator->errors()->add('application_id', __('errors/database.application_already_attached', [
                'application' => $application->name,
                'database' => $existing->name,
            ]));
        }
    }

    /**
     * An application can only use an engine it can speak.
     *
     * Same source of truth provisioning uses when it creates the database in
     * the first place (`InstallerManager::provisionDatabase()` passes
     * `acceptedEngines()`), so attaching cannot produce a pairing that creating
     * would have refused. A type that needs no database — `custom`, static,
     * a reverse proxy — declares nothing and accepts anything: the link is
     * bookkeeping there, and the panel does not know better than the user what
     * their own code connects to.
     *
     * 📌 Deliberately checks the engine and not `minimumEngineVersions()`.
     * That gate exists because an application dies inside its own installer
     * when the engine is too old, and attaching runs no installer — the
     * database is already there, possibly already in use. Refusing the link
     * would withhold bookkeeping over a version that only matters at install
     * time.
     */
    private function refuseUnusableEngine(Validator $validator, Application $application, string $engine): void
    {
        $installer = app(InstallerManager::class)->installerForType((string) $application->site_type);

        if ($installer === null || ! $installer->needsDatabase()) {
            return;
        }

        $accepted = $installer->acceptedEngines();

        if ($accepted === [] || in_array($engine, $accepted, true)) {
            return;
        }

        $validator->errors()->add('application_id', __('errors/database.engine_not_accepted', [
            'engine' => (string) config("server.databases.engines.{$engine}.label", $engine),
            'application' => $application->name,
            'accepted' => implode(' / ', array_map(
                fn (string $engine): string => (string) config("server.databases.engines.{$engine}.label", $engine),
                $accepted,
            )),
        ]));
    }
}

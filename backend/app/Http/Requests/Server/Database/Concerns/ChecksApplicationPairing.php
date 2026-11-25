<?php

namespace App\Http\Requests\Server\Database\Concerns;

use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\InstallerManager;
use Illuminate\Validation\Validator;

/**
 * The two rules a database and an application must meet to be paired.
 *
 * Shared by attaching an existing database and creating one for an application, so both paths
 * refuse the same pairings.
 */
trait ChecksApplicationPairing
{
    /**
     * One database per application, for now.
     *
     * Staging and cloning take `Database::where('application_id', …)->first()`, so with two
     * attached the one they copy is insertion order. Widening this means teaching those
     * strategies which database they mean, not deleting this check.
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
     * Same source as provisioning (`InstallerManager::provisionDatabase()` passes
     * `acceptedEngines()`). A type that needs no database (`custom`, static, a reverse proxy)
     * declares nothing and accepts anything: the link is bookkeeping there.
     *
     * 📌 Deliberately not `minimumEngineVersions()`: that gate protects an installer, and
     * attaching runs none; the database already exists.
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

<?php

namespace App\Http\Requests\Server\Database;

use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\InstallerManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Attach this database to an application, move it to another, or detach it.
 *
 * One nullable field rather than three verbs: the whole operation is setting
 * `databases.application_id`, and attach/move/detach are the three values it
 * can take.
 */
class UpdateDatabaseApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Present-but-null is the detach case, so `present` rather than
            // `required`: an absent key would otherwise silently detach.
            'application_id' => ['present', 'nullable', 'integer', Rule::exists('applications', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $applicationId = $this->validated('application_id');

            if ($applicationId === null) {
                return;
            }

            $application = Application::find($applicationId);

            if ($application === null) {
                return;
            }

            $this->refuseSecondDatabase($validator, $application);
            $this->refuseUnusableEngine($validator, $application);
        });
    }

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
    private function refuseSecondDatabase(Validator $validator, Application $application): void
    {
        $existing = Database::query()
            ->where('application_id', $application->id)
            ->whereKeyNot($this->route('database')?->id)
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
    private function refuseUnusableEngine(Validator $validator, Application $application): void
    {
        /** @var Database $database */
        $database = $this->route('database');

        $installer = app(InstallerManager::class)->installerForType((string) $application->site_type);

        if ($installer === null || ! $installer->needsDatabase()) {
            return;
        }

        $accepted = $installer->acceptedEngines();

        if ($accepted === [] || in_array($database->engine, $accepted, true)) {
            return;
        }

        $validator->errors()->add('application_id', __('errors/database.engine_not_accepted', [
            'engine' => (string) config("server.databases.engines.{$database->engine}.label", $database->engine),
            'application' => $application->name,
            'accepted' => implode(' / ', array_map(
                fn (string $engine): string => (string) config("server.databases.engines.{$engine}.label", $engine),
                $accepted,
            )),
        ]));
    }
}

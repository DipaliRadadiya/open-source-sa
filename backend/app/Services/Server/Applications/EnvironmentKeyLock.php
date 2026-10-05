<?php

namespace App\Services\Server\Applications;

use App\Contracts\SiteType;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;

/**
 * Keeps the Environment editor from changing an application's encryption key
 * (bug #17), or a key the panel itself relies on (bug #7).
 *
 * Saving n8n's `.env` with a different `N8N_ENCRYPTION_KEY` was accepted, and
 * every credential stored in n8n became permanently unreadable — with no error
 * until a workflow next ran. Which keys are locked is the site type's to say
 * ({@see SiteType::lockedEnvironmentKeys()}).
 *
 * A key is locked only once it holds a value: a file that has none yet has
 * nothing to lose, and adding one is how it gets one.
 */
class EnvironmentKeyLock
{
    public function __construct(
        private SiteTypeManager $types,
        private EnvironmentInspector $inspector,
    ) {}

    /**
     * The first locked key `$next` would change or remove, or null.
     */
    public function violation(Application $application, string $current, string $next): ?string
    {
        foreach ($this->keys($application) as $key) {
            $now = $this->held($current, $key);

            if ($now === []) {
                continue;
            }

            if ($this->held($next, $key) !== $now) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Why `$key` cannot change, for the editor (`$backup` false) or for
     * restoring one of its backups.
     */
    public function message(Application $application, string $key, bool $backup = false): string
    {
        $managed = in_array($key, $this->type($application)?->panelManagedEnvironmentKeys() ?? [], true);

        $line = match (true) {
            $managed && $backup => 'errors/application.environment_key_managed_backup',
            $managed => 'errors/application.environment_key_managed',
            $backup => 'errors/application.environment_key_locked_backup',
            default => 'errors/application.environment_key_locked',
        };

        return __($line, ['key' => $key]);
    }

    /**
     * @return array<int, string>
     */
    private function keys(Application $application): array
    {
        $type = $this->type($application);

        return [...$type?->lockedEnvironmentKeys() ?? [], ...$type?->panelManagedEnvironmentKeys() ?? []];
    }

    private function type(Application $application): ?SiteType
    {
        return $this->types->find($application->site_type);
    }

    /**
     * The distinct non-empty values a key is given, so a duplicated line is
     * not a change but a second, different value is.
     *
     * @return array<int, string>
     */
    private function held(string $raw, string $key): array
    {
        return array_values(array_unique(array_filter(
            $this->inspector->values($raw, $key),
            fn (string $value): bool => $value !== '',
        )));
    }
}

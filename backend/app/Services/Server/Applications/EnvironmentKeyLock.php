<?php

namespace App\Services\Server\Applications;

use App\Contracts\SiteType;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;

/**
 * Keeps the Environment editor from changing an application's encryption key
 * (bug #17).
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
        foreach ($this->types->find($application->site_type)?->lockedEnvironmentKeys() ?? [] as $key) {
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

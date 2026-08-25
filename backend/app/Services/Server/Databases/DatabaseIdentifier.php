<?php

namespace App\Services\Server\Databases;

use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates the shared name used by an application's database and user.
 *
 * MySQL permits 64 characters for a database but only 32 for an account, so
 * the smaller limit governs. The random suffix is never truncated: it is the
 * part that keeps similar or shortened application names apart.
 */
class DatabaseIdentifier
{
    private const MAX_LENGTH = 32;

    private const SUFFIX_LENGTH = 6;

    private const MAX_ATTEMPTS = 20;

    public function __construct(private DatabaseManager $databases) {}

    public function generate(string $label, ?string $prefix = null): string
    {
        return $this->candidate($label, $prefix);
    }

    public function generateAvailable(string $label, string $engineName, ?string $prefix = null): string
    {
        $engine = $this->databases->engine($engineName);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->generate($label, $prefix);

            if ($this->tracked($candidate, $engineName)) {
                continue;
            }

            if ($engine->identifierAvailable($candidate)) {
                return $candidate;
            }
        }

        $reference = (string) Str::uuid();

        Log::channel('server-ops')->error('database identifier allocation exhausted', [
            'feature' => 'database',
            'op' => 'identifier_allocate',
            'engine' => $engineName,
            'attempts' => self::MAX_ATTEMPTS,
            'reference' => $reference,
        ]);

        throw new DatabaseOperationException($reference);
    }

    private function candidate(string $label, ?string $prefix): string
    {
        $prefix = $this->normalize($prefix ?? '');
        $head = $prefix === '' ? '' : $prefix.'_';
        $baseLimit = self::MAX_LENGTH - strlen($head) - self::SUFFIX_LENGTH - 1;

        $base = Str::of($this->normalize($label))
            ->limit($baseLimit, '')
            ->rtrim('_')
            ->value();

        return $head.($base ?: 'app').'_'.Str::lower(Str::random(self::SUFFIX_LENGTH));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9_]+/', '_')
            ->trim('_')
            ->lower()
            ->value();
    }

    private function tracked(string $candidate, string $engineName): bool
    {
        if (Database::query()->where('engine', $engineName)->where('name', $candidate)->exists()) {
            return true;
        }

        return DatabaseUser::query()
            ->where('username', $candidate)
            ->where('host', 'localhost')
            ->whereHas('database', fn ($query) => $query->where('engine', $engineName))
            ->exists();
    }
}

<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Refuses a test run that would reach a real database.
 *
 * On 14 Oct and again on 28 Oct 2026 Pest ran in a live copy whose
 * bootstrap/cache/config.php overrode phpunit.xml's SQLite settings, and
 * RefreshDatabase wiped the real database it found there. TestCase runs this
 * right after the application boots and before any trait touches a
 * connection, so the only database a test can reach is an in-memory SQLite
 * one, or a SQLite file under database/ whose name says "testing".
 */
final class TestDatabaseGuard
{
    /** Why the run must stop, or null when the database is safe to use. */
    public static function refusal(Application $app): ?string
    {
        $config = $app->make('config');
        $connection = (string) $config->get('database.default');
        $settings = (array) $config->get("database.connections.{$connection}", []);
        $database = (string) ($settings['database'] ?? '');

        $reason = match (true) {
            $app->configurationIsCached() => 'the configuration is cached, so phpunit.xml cannot point the run at SQLite',
            $connection !== 'sqlite' || ($settings['driver'] ?? null) !== 'sqlite' => 'the default connection is not sqlite',
            ! empty($settings['url']) => 'the sqlite connection has a URL, which overrides its database',
            ! self::isTestingDatabase($app, $database) => 'the SQLite database is not :memory:',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        return sprintf(
            'Refusing to run tests: %s (connection "%s", database "%s"). Run tests in a worktree; delete bootstrap/cache/config.php.',
            $reason,
            $connection,
            $database,
        );
    }

    /** @throws RuntimeException when the run would reach a real database */
    public static function check(Application $app): void
    {
        if (($refusal = self::refusal($app)) !== null) {
            throw new RuntimeException($refusal);
        }
    }

    private static function isTestingDatabase(Application $app, string $database): bool
    {
        if ($database === ':memory:') {
            return true;
        }

        // A file only when it sits under this repo's database/ folder and says
        // it is for testing; realpath() so "database/../x" cannot pass.
        $folder = realpath($app->databasePath());
        $parent = realpath(dirname($database));

        return $folder !== false && $parent !== false
            && str_starts_with($parent.DIRECTORY_SEPARATOR, $folder.DIRECTORY_SEPARATOR)
            && str_contains(basename($database), 'testing');
    }
}

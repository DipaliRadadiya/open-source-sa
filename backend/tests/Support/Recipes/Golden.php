<?php

namespace Tests\Support\Recipes;

final class Golden
{
    /** Config order of config/server.php site_types. Never edit. */
    public const LEGACY = ['ghost', 'vaultwarden', 'gitea', 'forgejo', 'freshrss', 'stirlingpdf', 'ittools', 'glance', 'homepage', 'nocodb', 'matomo', 'mattermost', 'chatwoot', 'excalidraw', 'metabase', 'wikijs', 'grafana', 'bookstack', 'wordpress_container'];

    public const LOCALES = ['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'];

    public const APP_ID = 4242;

    public const APP_PORT = 20101;

    public const DOCROOT = '/home/owner/site/public_html';

    public static function dir(string $slug): string
    {
        return base_path("tests/Fixtures/recipes-golden/{$slug}");
    }

    public static function domain(string $slug): string
    {
        return str_replace('_', '-', $slug).'.golden.test';
    }

    public static function secret(string $slug, string $key): string
    {
        return substr(hash('sha256', "golden|{$slug}|{$key}"), 0, 32);
    }

    /** Compare, or capture a missing fixture. Existing fixtures are never overwritten. */
    public static function assertMatches(string $path, string $actual): void
    {
        if (getenv('RECIPES_GOLDEN_CAPTURE') === '1') {
            if (is_file($path)) {
                throw new \RuntimeException("Refusing to overwrite golden {$path}");
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            file_put_contents($path, $actual);
        }

        expect(is_file($path))->toBeTrue("Missing golden {$path}");
        expect($actual)->toBe(file_get_contents($path));
    }
}

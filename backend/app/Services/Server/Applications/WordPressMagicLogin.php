<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WpCli\WpCli;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Signs a panel user into a WordPress site as one of its administrators.
 *
 * Gated on its own permission and logged with the assumed account's name.
 *
 * Nothing is downloaded: the loader is a file in this repository, copied into the site. Never
 * accept a URL or code to run from the request.
 */
class WordPressMagicLogin
{
    /**
     * Long enough for a slow network and a redirect; short enough that a token captured in
     * transit is worthless.
     */
    private const TTL_SECONDS = 60;

    private const OPTION = 'sv_magic_login_token';

    public function __construct(
        private ServerOps $serverOps,
    ) {}

    /**
     * Every administrator of the site, as WordPress itself reports them.
     *
     * Read live: roles change and accounts are added outside the panel.
     *
     * @return array<int, array{id: int, login: string, name: string, email: string}>
     *
     * @throws ValidationException
     */
    public function administrators(Application $application): array
    {
        $result = $this->wp($application, [
            'user', 'list',
            '--role=administrator',
            '--fields=ID,user_login,display_name,user_email',
            '--format=json',
        ]);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.list_failed')],
            ]);
        }

        try {
            $rows = json_decode(trim($result->output()), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not the JSON we asked for (a PHP notice, usually). A failure of its own, not an
            // empty list, which would read as "no administrators".
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.list_unreadable')],
            ]);
        }

        if (! is_array($rows)) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.list_unreadable')],
            ]);
        }

        return array_values(array_map(fn (array $row) => [
            'id' => (int) $row['ID'],
            'login' => (string) $row['user_login'],
            'name' => (string) ($row['display_name'] ?: $row['user_login']),
            'email' => (string) $row['user_email'],
        ], $rows));
    }

    /**
     * Multisite is refused: `--role=administrator` lists per-site administrators, who cannot
     * reach the network admin, so the login would give less access than the button implies.
     */
    public function isMultisite(Application $application): bool
    {
        return ! $this->wp($application, ['core', 'is-installed', '--network'])->failed();
    }

    /**
     * Check, and mint a single-use token for one administrator, in one go.
     *
     * One WP-CLI run, because every run boots WordPress and slow runs make the browser block the
     * new tab. Each refusal keeps its own message.
     *
     * The script reaches WP-CLI over stdin, not argv: the payload holds the token's hash, and
     * argv is readable by every account on the box.
     *
     * @return array{url: string, token: string, expires_at: int, user: array{id: int, login: string, name: string, email: string}}
     *
     * @throws ValidationException
     */
    public function mint(Application $application, int $wpUserId): array
    {
        $this->installLoader($application);

        $token = Str::random(64);
        $expiresAt = time() + self::TTL_SECONDS;

        $result = $this->wp($application, ['eval-file', '-'], $this->mintScript([
            'hash' => hash('sha256', $token),
            'user_id' => $wpUserId,
            'expires_at' => $expiresAt,
        ]));

        $answer = $this->lastJsonLine($result->output());

        if ($result->failed() || $answer === null) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.mint_failed')],
            ]);
        }

        if (($answer['error'] ?? null) === 'multisite') {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.multisite_unsupported')],
            ]);
        }

        // The role is re-checked inside WordPress, not trusted from the request: a subscriber or a
        // demoted account must not get a token. The loader checks again at use.
        if (($answer['error'] ?? null) === 'not_administrator') {
            throw ValidationException::withMessages([
                'wp_user_id' => [__('errors/magic_login.not_an_administrator')],
            ]);
        }

        if (($answer['ok'] ?? false) !== true || ! is_array($answer['user'] ?? null)) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.mint_failed')],
            ]);
        }

        return [
            'url' => $application->url(),
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => (int) $answer['user']['id'],
                'login' => (string) $answer['user']['login'],
                'name' => (string) ($answer['user']['name'] ?: $answer['user']['login']),
                'email' => (string) $answer['user']['email'],
            ],
        ];
    }

    /**
     * The PHP WP-CLI runs for mint(). Values go through var_export(), so nothing in them can
     * become code. Not autoloaded: the token is read once, autoload would fetch it on every page.
     *
     * @param  array{hash: string, user_id: int, expires_at: int}  $payload
     */
    private function mintScript(array $payload): string
    {
        $option = var_export(self::OPTION, true);
        $value = var_export($payload, true);

        return <<<PHP
        <?php
        if (is_multisite()) { echo PHP_EOL.json_encode(['error' => 'multisite']); return; }
        \$payload = {$value};
        \$user = get_user_by('id', (int) \$payload['user_id']);
        if (! \$user || ! in_array('administrator', (array) \$user->roles, true)) { echo PHP_EOL.json_encode(['error' => 'not_administrator']); return; }
        delete_option({$option});
        if (! add_option({$option}, \$payload, '', false)) { echo PHP_EOL.json_encode(['error' => 'not_saved']); return; }
        echo PHP_EOL.json_encode(['ok' => true, 'user' => ['id' => \$user->ID, 'login' => \$user->user_login, 'name' => \$user->display_name, 'email' => \$user->user_email]]);
        PHP;
    }

    /**
     * The script's answer is its last line; anything before it is site output (notices, echoes).
     *
     * @return array<string, mixed>|null
     */
    private function lastJsonLine(string $output): ?array
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        $decoded = json_decode((string) end($lines), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Put the loader in place. Rewritten every time, so an edited or half-written copy is repaired.
     *
     * Written AS the site user: `wp-content` is the user's, and as root a planted symlink at
     * `mu-plugins` or `sv-magic-login.php` would make root write anywhere. As the user it reaches
     * only what the user could already write.
     *
     * @throws ValidationException
     */
    private function installLoader(Application $application): void
    {
        $dir = $application->documentRoot().'/wp-content/mu-plugins';
        $path = $dir.'/sv-magic-login.php';
        $asUser = ['runuser', '-u', $application->systemUser->username, '--'];

        $this->serverOps->run(
            [...$asUser, 'mkdir', '-p', '-m', '0755', $dir],
            $this->context($application, 'ensure_mu_plugins'),
        );

        $written = $this->serverOps->run(
            [...$asUser, 'tee', $path],
            $this->context($application, 'write_magic_login_loader'),
            input: (string) file_get_contents(resource_path('stubs/sv-magic-login.php')),
        );

        if ($written->failed()) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.loader_failed')],
            ]);
        }

        $this->serverOps->run(
            [...$asUser, 'chmod', '0644', $path],
            $this->context($application, 'chmod_magic_login_loader'),
        );
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function wp(Application $application, array $arguments, ?string $input = null): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge(
                ['runuser', '-u', $application->systemUser->username, '--'],
                // On the site's own PHP, not the shebang's server default.
                app(WpCli::class)->command($application),
                $arguments,
                [
                    '--path='.$application->documentRoot(),
                    // A broken plugin or theme must not hide the administrator list.
                    '--skip-plugins',
                    '--skip-themes',
                ],
            ),
            $this->context($application, 'magic_login'),
            timeout: 60,
            input: $input,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'magic_login', 'op' => $op, 'application' => $application->id];
    }
}

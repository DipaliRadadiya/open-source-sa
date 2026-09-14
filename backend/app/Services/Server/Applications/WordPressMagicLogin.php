<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Signs a panel user into a WordPress site as one of its administrators.
 *
 * The panel already holds the keys to this site — it owns the files and the
 * database credentials — so this grants nothing that was not already reachable
 * by someone with file access. What it changes is the number of steps, which
 * is precisely why it is gated on its own permission and written to the
 * activity log with the name of the account that was assumed.
 *
 * **Nothing is downloaded.** The loader is a file in this repository, copied
 * into the site. The implementation this replaces took a URL as a request
 * parameter, fetched it into the customer's document root and executed it —
 * arbitrary remote code execution offered as a feature. A panel that can be
 * told which code to run on a customer's site is not a panel.
 */
class WordPressMagicLogin
{
    /**
     * Long enough to cross a slow network and a redirect; short enough that a
     * token captured in transit is worthless before it can be used by hand.
     */
    private const TTL_SECONDS = 60;

    private const OPTION = 'sv_magic_login_token';

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    /**
     * Every administrator of the site, as WordPress itself reports them.
     *
     * Read from WordPress rather than from anything the panel stored at
     * install time: roles change, accounts are added by people who never touch
     * this panel, and an install-time snapshot would offer a list of users who
     * may no longer be administrators — or miss the one the operator needs.
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
            // wp-cli printed something that is not the JSON we asked for —
            // a PHP notice from the site, most often. Reported as its own
            // failure rather than rendered as an empty list, which would read
            // as "this site has no administrators".
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
     * A network install needs a different answer than this gives.
     *
     * `--role=administrator` on multisite lists per-site administrators, who
     * cannot reach the network admin at all — so the operator would be signed
     * in with less access than the button implies, on a screen that looks
     * right. Refused rather than half-answered; the honest fix is `super-admin`
     * handling, which is its own piece of work.
     */
    public function isMultisite(Application $application): bool
    {
        return ! $this->wp($application, ['core', 'is-installed', '--network'])->failed();
    }

    /**
     * Mint a single-use token for one administrator and return where to post it.
     *
     * @return array{url: string, token: string, expires_at: int}
     *
     * @throws ValidationException
     */
    public function mint(Application $application, int $wpUserId): array
    {
        $this->installLoader($application);

        // Generated here and never stored here. The site keeps only the SHA-256
        // of it, so the panel's own database cannot be read to log into
        // anybody's WordPress — the same reason a password is not stored.
        $token = Str::random(64);
        $expiresAt = time() + self::TTL_SECONDS;

        $payload = json_encode([
            'hash' => hash('sha256', $token),
            'user_id' => $wpUserId,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR);

        // `--autoload=no`: this row is read on exactly one request in its
        // 60-second life, and autoloaded options are fetched on every page
        // load of the whole site.
        $written = $this->wp($application, [
            'option', 'update', self::OPTION, $payload,
            '--format=json', '--autoload=no',
        ]);

        if ($written->failed()) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.mint_failed')],
            ]);
        }

        return [
            'url' => $application->url(),
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Put the loader in place, owned by the site user like everything else it
     * loads. Rewritten every time rather than only when absent, so a site whose
     * copy was edited or half-written is repaired by using the feature.
     *
     * @throws ValidationException
     */
    private function installLoader(Application $application): void
    {
        $dir = $application->documentRoot().'/wp-content/mu-plugins';
        $path = $dir.'/sv-magic-login.php';
        $owner = $application->systemUser->username;

        $this->serverOps->run(
            ['install', '-d', '-m', '0755', '-o', $owner, '-g', $owner, $dir],
            $this->context($application, 'ensure_mu_plugins'),
        );

        $written = $this->files->put(
            $path,
            (string) file_get_contents(resource_path('stubs/sv-magic-login.php')),
            $this->context($application, 'write_magic_login_loader'),
        );

        if ($written->failed()) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.loader_failed')],
            ]);
        }

        $this->serverOps->run(
            ['chown', "{$owner}:{$owner}", $path],
            $this->context($application, 'chown_magic_login_loader'),
        );
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function wp(Application $application, array $arguments): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge(
                ['runuser', '-u', $application->systemUser->username, '--'],
                [(string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp')],
                $arguments,
                [
                    '--path='.$application->documentRoot(),
                    // One broken plugin or theme must not be able to hide the
                    // administrator list — the moment an operator most needs to
                    // get into a site is usually the moment something on it is
                    // broken.
                    '--skip-plugins',
                    '--skip-themes',
                ],
            ),
            $this->context($application, 'magic_login'),
            timeout: 60,
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

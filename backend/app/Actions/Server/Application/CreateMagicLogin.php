<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\WordPressMagicLogin;
use Illuminate\Validation\ValidationException;

/**
 * Hands a panel user a one-time key to a WordPress administrator account.
 *
 * Everything refused here is refused *before* a token exists, so a rejected
 * attempt leaves nothing behind on the site.
 */
class CreateMagicLogin
{
    public function __construct(
        private WordPressMagicLogin $magicLogin,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{url: string, token: string, expires_at: int, user: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function execute(Application $application, int $wpUserId): array
    {
        // Plain HTTP sends the token, and then the authentication cookie it
        // buys, across the network in clear text. Anyone on the path becomes an
        // administrator of this site — and unlike most eavesdropping, they get
        // a durable session out of it rather than one request. Refused rather
        // than warned about: the whole value of the feature is one click, and a
        // warning on a one-click action is a warning nobody reads.
        if ($application->scheme() !== 'https') {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.requires_https')],
            ]);
        }

        // Multisite, "is this still an administrator", and the token write
        // are all asked inside one WP-CLI run now (FS-C16) — see mint().
        $session = $this->magicLogin->mint($application, $wpUserId);
        $chosen = $session['user'];
        unset($session['user']);

        // Logged before the token is returned, and naming the account that was
        // assumed. An impersonation feature whose audit trail says only that
        // "someone used magic login" is not an audit trail — the question after
        // the fact is always *as whom*.
        $this->activityLogger->log('application.magic_login', $application, [
            'domain' => $application->domain,
            'wp_user' => $chosen['login'],
        ]);

        return [...$session, 'user' => $chosen];
    }
}

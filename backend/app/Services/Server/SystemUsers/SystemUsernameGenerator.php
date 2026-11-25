<?php

namespace App\Services\Server\SystemUsers;

use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Models\SystemUser;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A Linux account name for a site the user has just named.
 *
 * Derived from the application's name, not random, so `ps` and `ls -l` show which site an
 * account belongs to. Validity is enforced here rather than left to `useradd`, whose failure
 * would surface as an unreadable shell error mid-creation.
 */
class SystemUsernameGenerator
{
    /**
     * useradd's ceiling on most distributions. The base is trimmed before a suffix is added;
     * truncating afterwards could give two sites the same name.
     */
    private const MAX_LENGTH = 32;

    private const SUFFIX_LENGTH = 4;

    /** Enough that exhausting them means something is wrong, not unlucky. */
    private const ATTEMPTS = 12;

    public function __construct(private ServerAccountNames $names) {}

    public function forApplication(string $applicationName): string
    {
        $base = $this->base($applicationName);

        if ($this->available($base)) {
            return $base;
        }

        // Room for `-` plus the suffix, taken off the base.
        $trimmed = rtrim(
            Str::limit($base, self::MAX_LENGTH - self::SUFFIX_LENGTH - 1, ''),
            '-',
        );

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            // Lowercase alphanumerics only: Str::random() includes uppercase
            // and would produce a name useradd refuses.
            $candidate = $trimmed.'-'.Str::lower(Str::random(self::SUFFIX_LENGTH));

            if ($this->available($candidate)) {
                return $candidate;
            }
        }

        // Every candidate read as taken, in practice because the probe cannot answer. A
        // ValidationException gives the user a message instead of a 500.
        throw ValidationException::withMessages([
            'generate_system_user' => [__('errors/application.system_user_name_unavailable')],
        ]);
    }

    /**
     * The name before uniqueness is considered.
     */
    private function base(string $applicationName): string
    {
        // Str::slug transliterates first: "Café Blog" becomes "cafe-blog".
        $slug = Str::slug($applicationName, '-');

        // useradd wants a letter or underscore first ("2024-campaign" is refused).
        $slug = ltrim($slug, '0123456789-');

        $slug = Str::limit($slug, self::MAX_LENGTH, '');
        $slug = rtrim($slug, '-');

        // Nothing usable survived (untransliterable script, emoji): fall back to a generated name.
        if ($slug === '') {
            return 'app-'.Str::lower(Str::random(self::SUFFIX_LENGTH));
        }

        // A reserved name is prefixed, not suffixed: `root-a1b2` would be a different, valid account.
        if (in_array($slug, StoreSystemUserRequest::RESERVED, true)) {
            return Str::limit('app-'.$slug, self::MAX_LENGTH, '');
        }

        return $slug;
    }

    /**
     * Free in the panel's records *and* on the box.
     *
     * Both, because an account created by hand or another tool exists in /etc/passwd without a
     * row here.
     */
    public function available(string $username): bool
    {
        if (SystemUser::where('username', $username)->exists()) {
            return false;
        }

        // `getent passwd`, not `id -u`: `id` writes to stderr on a miss, and ServerOps only
        // accepts an expected non-zero exit as an answer when stderr is empty.
        //
        // A group counts too: useradd makes one of the same name, so a name that is only a
        // group (`docker`) fails just like a user.
        //
        // A command that could not run (sudo refusing, getent missing) must not read as "free":
        // unanswerable is treated as taken, so the caller tries another name.
        return $this->names->taken($username) === false;
    }
}

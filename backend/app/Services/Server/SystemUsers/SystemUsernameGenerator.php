<?php

namespace App\Services\Server\SystemUsers;

use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Models\SystemUser;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A Linux account name for a site the user has just named.
 *
 * Derived from the application's own name rather than generated at random,
 * because this string is what somebody reads in `ps`, in `ls -l` and in the
 * file manager when they are trying to work out which site an account belongs
 * to. `sv-a1b2c3d4` answers that question with nothing.
 *
 * Everything the name has to survive is enforced here rather than left to
 * `useradd` to reject: a failure at that point comes back as a shell error in
 * the middle of creating an application, which is both unreadable and too late.
 */
class SystemUsernameGenerator
{
    /**
     * useradd's own ceiling on most distributions. The suffix has to fit
     * inside it too, which is why the base is trimmed before one is added
     * rather than the whole thing being truncated afterwards — truncating
     * afterwards is how two different sites end up with the same name.
     */
    private const MAX_LENGTH = 32;

    private const SUFFIX_LENGTH = 4;

    /** Enough that exhausting them means something is wrong, not unlucky. */
    private const ATTEMPTS = 12;

    public function __construct(private ServerOps $serverOps) {}

    public function forApplication(string $applicationName): string
    {
        $base = $this->base($applicationName);

        if ($this->available($base)) {
            return $base;
        }

        // Room for `-` plus the suffix, taken off the base rather than off the
        // finished name.
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

        // Reached when every candidate reads as taken — in practice, when the
        // probe itself cannot answer, because a dozen random suffixes do not
        // collide by chance. A ValidationException rather than a raw throw:
        // the user asked for a site and needs a sentence, not a 500.
        throw ValidationException::withMessages([
            'generate_system_user' => [__('errors/application.system_user_name_unavailable')],
        ]);
    }

    /**
     * The name before uniqueness is considered.
     */
    private function base(string $applicationName): string
    {
        // Str::slug transliterates first, so "Café Blog" becomes "cafe-blog"
        // rather than losing the word entirely.
        $slug = Str::slug($applicationName, '-');

        // useradd wants a letter or underscore first. A site called "2024
        // Campaign" slugs to "2024-campaign", which it refuses.
        $slug = ltrim($slug, '0123456789-');

        $slug = Str::limit($slug, self::MAX_LENGTH, '');
        $slug = rtrim($slug, '-');

        // Nothing usable survived — a name written entirely in a script slug
        // cannot transliterate, or in emoji. A generated name is the honest
        // answer here; refusing to create the application would be worse.
        if ($slug === '') {
            return 'app-'.Str::lower(Str::random(self::SUFFIX_LENGTH));
        }

        // A reserved name is not a collision to suffix around — `root` and
        // `root-a1b2` are different accounts and only one of them is refused,
        // so prefixing keeps the site's name readable instead.
        if (in_array($slug, StoreSystemUserRequest::RESERVED, true)) {
            return Str::limit('app-'.$slug, self::MAX_LENGTH, '');
        }

        return $slug;
    }

    /**
     * Free in the panel's records *and* on the box.
     *
     * Both, because they can disagree: an account created by hand, or by
     * another tool, exists in /etc/passwd without a row here. Checking only the
     * table would hand out a name `useradd` then refuses, turning a solvable
     * collision into a failed application.
     */
    private function available(string $username): bool
    {
        if (SystemUser::where('username', $username)->exists()) {
            return false;
        }

        // `getent passwd`, not `id -u`. Both answer the question, but `id`
        // writes "no such user" to stderr on the miss, and ServerOps only
        // counts an expected non-zero exit as an *answer* when stderr is
        // empty — so `id` would report every absent user as an unreadable
        // failure. `getent` exits 2 and says nothing, which is the shape
        // ServerOps is built to read. Verified on the box rather than assumed.
        $result = $this->serverOps->run(
            ['getent', 'passwd', $username],
            ['feature' => 'system_user', 'op' => 'probe_username'],
            expectedExitCodes: [2],
        );

        // Not `failed()`. A command that could not run at all — sudo refusing,
        // getent missing — must not read as "the name is free", because the
        // next step would be a useradd that fails for a reason nobody can see.
        // Unanswerable is treated as taken, so the caller tries another name.
        if (! $result->answered) {
            return false;
        }

        return ! $result->ok;
    }
}

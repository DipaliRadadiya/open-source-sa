<?php

namespace App\Rules;

use App\Models\Application;
use App\Services\Server\Applications\SlugConflict;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A site name whose slug does not already name someone else's file.
 *
 * The name becomes the slug, and the slug is a filename: the PHP pool, the
 * web server vhost, the OLS vhost directory. Uniqueness was checked against
 * the `applications` table, which knows only what the panel has made — so
 * `www` (the distro's PHP pool) and `panel` (the panel's own vhost) were both
 * accepted and both overwrote the file they landed on.
 *
 * Same shape as {@see AvailablePort}, and for the same reason: this is a
 * panel for someone else's machine, so the only honest check is against that
 * machine rather than against the panel's own records.
 *
 * {@see SlugConflict} for why this asks the disk instead of holding a list of
 * reserved words, and for why not knowing is treated as "fine".
 */
class AvailableSiteName implements ValidationRule
{
    public function __construct(private ?Application $except = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || blank($value)) {
            return;
        }

        // The slug the site would actually get, not the raw name — `WWW` and
        // `www` are one file, and the collision is a property of the slug.
        $slug = Application::uniqueSlug($value, $this->except?->id);

        $conflict = app(SlugConflict::class)->for($slug, $this->except);

        if ($conflict !== null) {
            $fail(__("validation.site_name_taken_{$conflict}", ['name' => $slug]));
        }
    }
}

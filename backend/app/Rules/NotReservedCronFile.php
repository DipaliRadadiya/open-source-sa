<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rejects names whose slug would collide with a common system /etc/cron.d
 * file — since our files are the bare slug (no prefix), a name like "php"
 * would overwrite the OS cron file at that path.
 */
class NotReservedCronFile implements ValidationRule
{
    /**
     * @var array<int, string>
     */
    public const RESERVED = [
        'php', 'e2scrub_all', 'sysstat', 'anacron', 'certbot', 'mdadm',
        'popularity-contest', 'ntpsec', 'plocate', 'mlocate', 'apt-compat',
        'dpkg', 'cron', 'crontab', 'apport', 'update-notifier-common',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && in_array(Str::slug($value), self::RESERVED, true)) {
            $fail('errors/cronjob.reserved_name')->translate();
        }
    }
}

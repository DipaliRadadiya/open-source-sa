<?php

namespace App\Services\Recipes;

use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Server\Docker\Images\ImageReference;

final class RecipeValueRules
{
    public static function assertValid(string $name, string|int|null $value, ?string $secretComplexity = null, ?string $inputType = null): void
    {
        if ($value === null) {
            if ($name === 'cpu_limit') {
                return;
            }
            throw new RecipeRenderException($name);
        }
        $v = (string) $value;
        if (preg_match('/[\r\n]/', $v)) {
            throw new RecipeRenderException($name);
        }
        if (in_array($name, ['app_port', 'container_port'], true)) {
            if (! is_int($value) || $value < 1 || $value > 65535) {
                throw new RecipeRenderException($name);
            }

            return;
        }
        if (str_starts_with($name, 'image.')) {
            if (! self::imageRefValid($v)) {
                throw new RecipeRenderException($name);
            }

            return;
        }
        $pattern = match (true) {
            $name === 'project' => '^sv-app-[0-9]{1,10}$',
            in_array($name, ['memory_limit', 'db_memory_limit'], true) => '^[0-9]{1,6}[bkmgBKMG]?$',
            $name === 'cpu_limit' => '^[0-9]{1,3}(\.[0-9]{1,3})?$',
            $name === 'url' => '^https?://[a-z0-9.-]{1,253}(:[0-9]{1,5})?$',
            $name === 'domain' => '^[a-z0-9.-]{1,253}$',
            $name === 'site_root' => '^/[A-Za-z0-9._/-]{1,255}$',
            str_starts_with($name, 'volume.') => '^sv-app-[0-9]{1,10}_[a-z][a-z0-9-]{0,30}$',
            str_starts_with($name, 'secret.') => $secretComplexity === 'complex' ? '^[A-Za-z0-9!@#%^*_=+-]{8,256}$' : '^[A-Za-z0-9]{8,256}$',
            str_starts_with($name, 'input.') => ($inputType === 'email' || $name === 'input.admin_email') ? '^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}$' : '^[a-z][a-z0-9_-]{2,31}$',
            default => null,
        };
        if ($pattern === null || preg_match('~'.$pattern.'~D', $v) !== 1) {
            throw new RecipeRenderException($name);
        }
    }

    public static function imageRefValid(string $ref): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/D', $ref) !== 1 || ImageReference::parse($ref) === null) {
            return false;
        }

        return str_contains($ref, '@sha256:') || preg_match('~:[A-Za-z0-9_][A-Za-z0-9_.-]*$~D', substr($ref, (strrpos($ref, '/') ?: -1) + 1)) === 1;
    }
}

<?php

namespace App\Enums;

/**
 * The login shells a system user may be given.
 *
 * The value is the binary path (what `usermod -s` takes); the label is what the panel shows, so
 * the two entries that *deny* login are not indistinguishable from the three that grant it.
 */
enum LoginShell: string
{
    case Bash = '/bin/bash';
    case Sh = '/bin/sh';
    case Zsh = '/usr/bin/zsh';
    case NoLogin = '/usr/sbin/nologin';
    case FalseShell = '/bin/false';

    /**
     * Whether this shell lets the account actually start a session.
     *
     * The pair that does not: a site's files need an owner who has no business logging in.
     */
    public function allowsLogin(): bool
    {
        return match ($this) {
            self::NoLogin, self::FalseShell => false,
            default => true,
        };
    }

    public function title(): string
    {
        return __('shell.'.$this->key().'.title');
    }

    public function description(): string
    {
        return __('shell.'.$this->key().'.description');
    }

    /** Translation key segment — the path is not usable as one. */
    private function key(): string
    {
        return match ($this) {
            self::Bash => 'bash',
            self::Sh => 'sh',
            self::Zsh => 'zsh',
            self::NoLogin => 'nologin',
            self::FalseShell => 'false',
        };
    }

    /**
     * The picker's contents, login-capable first, so the limited options are not a default.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $shell): array => [
            'value' => $shell->value,
            'title' => $shell->title(),
            'description' => $shell->description(),
            'allows_login' => $shell->allowsLogin(),
        ], self::cases());
    }

    /** @return array<int, string> */
    public static function paths(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Label for a stored value, tolerating one no longer offered: an adopted user can carry any
     * shell, and a blank label would read as "no shell".
     */
    public static function titleFor(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return self::tryFrom($path)?->title() ?? $path;
    }

    public static function allowsLoginFor(?string $path): ?bool
    {
        return $path === null ? null : self::tryFrom($path)?->allowsLogin();
    }
}

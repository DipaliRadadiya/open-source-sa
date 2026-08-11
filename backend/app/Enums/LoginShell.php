<?php

namespace App\Enums;

/**
 * The login shells a system user may be given.
 *
 * These are paths to binaries, and the panel used to show them as exactly
 * that — a dropdown reading `/usr/sbin/nologin` tells someone who has never
 * administered a Linux box nothing about what they are choosing, and the two
 * entries that *deny* login look no different from the three that grant it.
 * The value stays the path (it is what `usermod -s` takes); the label is what
 * the panel shows.
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
     * The pair that does not is the whole reason the label matters: a site's
     * files still need an owner, but that owner has no business logging in.
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
     * The picker's contents, login-capable first, so the safe-but-limited
     * options are not what someone lands on by accident.
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
     * Label for a stored value, tolerating one that is no longer offered — a
     * user adopted from an existing server can carry any shell at all, and a
     * blank label would read as "no shell" when it means "not one of ours".
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

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A start command systemd can actually execute.
 *
 * `ExecStart` is not a shell. systemd execs the binary directly, so a command
 * with `&&`, a pipe or a variable does not fail at validation time — it fails
 * at start time, with an error that names none of that as the cause.
 *
 * And a package-manager start is worse than an error, because it *works*:
 * `npm start` forks node as a child, so systemd tracks the wrapper. SIGTERM
 * reaches npm and not the app, shutdown hangs until the timeout kills it, and
 * PM2 cluster mode — when it arrives — silently degrades to a single process
 * because there is no JS file for it to fork. Everything looks fine until it
 * matters.
 */
class StartCommand implements ValidationRule
{
    /** Shell constructs `ExecStart` cannot run. */
    private const SHELL = ['&&', '||', '|', ';', '$(', '`', '>', '<', '&'];

    /** Wrappers that fork the real process and break signal handling. */
    private const WRAPPERS = ['npm', 'yarn', 'pnpm', 'bun', 'npx'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $command = trim((string) $value);

        if ($command === '') {
            return;
        }

        foreach (self::SHELL as $token) {
            if (str_contains($command, $token)) {
                $fail(__('validation.start_command_shell', ['token' => $token]));

                return;
            }
        }

        $binary = preg_split('/\s+/', $command)[0] ?? '';

        if (in_array(basename($binary), self::WRAPPERS, true)) {
            $fail(__('validation.start_command_wrapper', ['binary' => basename($binary)]));
        }
    }
}

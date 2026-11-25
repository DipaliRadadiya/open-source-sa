<?php

namespace App\Console\Commands;

use App\Actions\Auth\RegisterFirstAdmin;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Create the panel's first administrator at install time.
 *
 * Until a first user exists anyone who opens the panel can register, and the first
 * registration is the administrator. Creating the account before the panel is reachable
 * closes registration from the start.
 *
 * Goes through RegisterFirstAdmin, the same code as the sign-up screen, so the two ways
 * of making the first admin cannot drift.
 *
 * The password comes on standard input, never as an argument: arguments are readable by
 * every account through `ps`, and the installer's run() logs a failing command line.
 *
 * Safe to run again: the same username already there is "done", a different administrator
 * is left alone (never a second one, never a password overwrite). Both exit 0.
 */
class PanelCreateAdmin extends Command
{
    protected $signature = 'panel:create-admin {--username= : The administrator\'s username}';

    protected $description = 'Create the first administrator; the password is read from standard input';

    public function handle(RegisterFirstAdmin $register): int
    {
        $username = (string) $this->option('username');

        // Strip only the herestring's line ending: spaces are part of the password.
        $password = rtrim($this->rawInput(), "\r\n");

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => ['required', 'string', 'alpha_dash:ascii', 'min:3', 'max:32'],
                'password' => ['required', 'string', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            // Field names and rules only: this output goes to the install log.
            $this->error('Cannot create the administrator: '.implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->info("The administrator {$username} already exists; nothing changed.");

            return self::SUCCESS;
        }

        try {
            $register->execute(['name' => $username, 'username' => $username, 'password' => $password]);
        } catch (AuthorizationException) {
            $this->warn('An administrator already exists; left as it is.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Could not create the administrator: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Administrator {$username} created; registration is closed.");

        return self::SUCCESS;
    }

    /** As PanelCentralToken::rawInput — the console stream when set, else stdin. */
    private function rawInput(): string
    {
        $stream = $this->input instanceof StreamableInputInterface
            ? $this->input->getStream()
            : null;

        if ($stream !== null) {
            return (string) stream_get_contents($stream);
        }

        return (string) file_get_contents('php://stdin');
    }
}

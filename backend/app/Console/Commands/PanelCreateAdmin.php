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
 * Create the panel's first administrator at install time (2026-10-17).
 *
 * A server set up by Central has a public address before its owner ever opens
 * it, and until a first user exists anyone who opens it can register — and the
 * first registration is the administrator. So when Central hands the installer
 * a username and password, this creates that account before the panel is
 * reachable, and registration is closed from the start.
 *
 * Through RegisterFirstAdmin, the same code as the sign-up screen: one lock,
 * one transaction, the permission catalog synced, the Administrator role. Two
 * ways to make the first admin would drift.
 *
 * The password comes on standard input, never as an argument: an argument is
 * readable by every account through `ps`, and the installer's run() writes a
 * failing command line into its log.
 *
 * Safe to run again. The same username already there is "done"; a different
 * administrator is left alone — this never adds a second one or overwrites a
 * password. Both exit 0, with what happened in the output.
 */
class PanelCreateAdmin extends Command
{
    protected $signature = 'panel:create-admin {--username= : The administrator\'s username}';

    protected $description = 'Create the first administrator; the password is read from standard input';

    public function handle(RegisterFirstAdmin $register): int
    {
        $username = (string) $this->option('username');

        // Only the line ending a herestring adds: spaces are a legal part of a
        // password, and trimming them would store a different one.
        $password = rtrim($this->rawInput(), "\r\n");

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => ['required', 'string', 'alpha_dash:ascii', 'min:3', 'max:32'],
                'password' => ['required', 'string', 'max:128', 'regex:/^[^\x00-\x1F\x7F]+$/', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            // Field names and rules only — this output goes to the install log.
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

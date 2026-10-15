<?php

namespace App\Services\Recipes\Hooks;

use App\Models\Application;
use App\Services\Recipes\Recipe;

final class ChatwootHook implements RecipeHook
{
    /**
     * Claim the instance before a stranger can.
     *
     * `POST /installation/onboarding` is open only while the Redis flag the
     * production seeds set is present, and the controller deletes that flag once
     * it has built the account — so this both creates the administrator and
     * closes the door, and a second call is a redirect rather than a second
     * account. `ApplicationController` skips `verify_authenticity_token`, so a
     * form post with no browser session is accepted.
     *
     * `subscribe_to_updates` is deliberately absent: present and non-blank, the
     * controller registers the installation with ChatwootHub — someone else's
     * server learning about this one, from a panel whose point is that it does
     * not do that.
     *
     * @return array{path: string, fields: array<string, string>}|null
     */
    public function firstRunClaim(Application $application, Recipe $recipe): ?array
    {
        $settings = $application->installSettings();
        /** @var array<string, string> $secrets Stored credentials are keyed by their declared secret names. */
        $secrets = (array) ($application->docker_secrets ?? []);

        $email = (string) ($settings['admin_email'] ?? '');
        $password = (string) ($secrets['ADMIN_PASSWORD'] ?? '');

        if ($email === '' || $password === '') {
            return null;
        }

        return [
            'path' => '/installation/onboarding',
            'fields' => [
                'user[name]' => 'Admin',
                'user[company]' => $application->name,
                'user[email]' => $email,
                'user[password]' => $password,
            ],
        ];
    }
}

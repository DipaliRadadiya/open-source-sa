<?php

/*
 * Settings feature strings.
 */

return [
    'reboot_schedule' => [
        'day_of_month' => 'Tag des Monats',
        'frequency' => [
            'daily' => 'Täglich',
            'weekly' => 'Wöchentlich',
            'monthly' => 'Monatlich',
        ],
        'day' => [
            0 => 'Sonntag',
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
        ],
    ],
    'redis' => [
        'password_applying' => 'Das Redis-Passwort wird angewendet. Laden Sie gleich neu, um es zu bestätigen.',
        'policy_evicts_panel_queue' => 'Das Panel speichert seine wartenden Jobs in diesem Redis. Eine allkeys-Richtlinie kann einen ausstehenden Job stillschweigend entfernen, sobald Redis voll ist, und ist hier daher nicht erlaubt. Verwenden Sie noeviction oder eine volatile-Richtlinie.',
    ],
];

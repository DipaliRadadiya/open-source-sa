<?php

/*
 * Copy for one application's PHP settings.
 *
 * The error messages carry the most important information on the screen:
 * whether anything on the server was actually changed. A failed pool test
 * changes nothing at all, and saying so is the difference between a calm
 * retry and someone panicking about a site they think they just broke.
 */

return [
    'presets' => [
        'low' => [
            'title' => 'Low traffic',
            'description' => 'A couple of workers. Right for most small sites, and the kindest to a small server.',
        ],
        'balanced' => [
            'title' => 'Balanced',
            'description' => 'Handles normal traffic without reserving memory it rarely needs.',
        ],
        'high' => [
            'title' => 'High traffic',
            'description' => 'Keeps workers warm. Use when the site is genuinely busy — it reserves memory whether it is used or not.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recommended',
            'description' => 'Blocks every way to run a program from inside PHP — what a web shell needs, and what a normal site almost never does.',
        ],
        'strict' => [
            'title' => 'Strict',
            'description' => 'Adds process, user and socket inspection on top of the recommended list. Matches typical shared-hosting hardening, and may break a site that uses the sockets extension.',
        ],
    ],

    'errors' => [
        'unsupported_stack' => 'This server runs OpenLiteSpeed, which does not use PHP-FPM pools.',
        'already_isolated' => 'This site already has its own PHP pool.',
        'not_isolated' => 'This site is not isolated.',
        'needs_isolation' => 'This site does not have its own PHP pool yet, so these limits could not be enforced. Give it one first, then save.',
        'basedir_absolute' => 'Every path must be absolute, starting with /. “:path” is not.',
        'basedir_root' => '“/” allows the whole filesystem, which would leave open_basedir switched on but enforcing nothing. Turn the setting off instead.',
        'basedir_traversal' => '“:path” is not allowed — paths cannot contain “..”.',
        'write_failed' => 'The pool configuration could not be written. Nothing was changed.',
        'config_test_failed' => 'PHP-FPM rejected the configuration, so it was not applied and nothing was reloaded. The site is still being served exactly as before.',
        'reload_failed' => 'PHP-FPM would not reload, so the previous configuration was restored.',
        'no_sections' => 'Section headers are not allowed here — they would start a second pool inside this one.',
        'function_list' => 'This must be a comma-separated list of function names.',
    ],
];

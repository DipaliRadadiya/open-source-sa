<?php

/*
 * Copy for the application environment screen.
 *
 * `checks` mirrors EnvironmentInspector one code at a time — a check added
 * there needs a title and a detail here, in every locale, or it renders as its
 * own key. Placeholders: :key is the variable name, :line the line number.
 */

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => 'Unknown',
    ],

    'checks' => [
        'file_exposed' => [
            'title' => 'The environment file can be reached over the web',
            'detail' => 'This site serves the directory its .env sits in, so the file is one URL away and Apache does not deny dotfiles by name. Set a web root (a Laravel app serves public/), which moves the served directory below the file.',
        ],
        'app_debug_on' => [
            'title' => 'Debug mode is on',
            'detail' => 'Visitors who trigger an error see a full stack trace, including database credentials. Set APP_DEBUG to false on a live site.',
        ],
        'app_env_local' => [
            'title' => 'The site is running in a development environment',
            'detail' => 'APP_ENV is set to a development value, which changes how errors, caching and mail behave. Set it to production on a live site.',
        ],
        'app_key_missing' => [
            'title' => 'APP_KEY is missing',
            'detail' => 'Without it the application cannot decrypt sessions or cookies, and will usually refuse to start.',
        ],
        'next_public_secret' => [
            'title' => '":key" is sent to every visitor',
            'detail' => 'Anything prefixed NEXT_PUBLIC_ is built into the browser bundle. A secret here is already public.',
        ],
        'duplicate_key' => [
            'title' => '":key" is set more than once',
            'detail' => 'Only the last one takes effect, so the value you are looking at may not be the value in use. Line :line.',
        ],
        'syntax_no_equals' => [
            'title' => 'Line :line has no "="',
            'detail' => 'Every line must be KEY=value, a comment, or blank.',
        ],
        'syntax_bad_key' => [
            'title' => 'Line :line is not a valid variable',
            'detail' => 'A name must start with a letter or underscore and contain only letters, numbers and underscores.',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" has an unclosed quote',
            'detail' => 'The value on line :line opens a quote it never closes, so it will run into the lines below it.',
        ],
        'syntax_export' => [
            'title' => '":key" uses "export"',
            'detail' => 'This application reads its environment through systemd, which rejects the export keyword and will not start. Remove it. Line :line.',
        ],
    ],

];

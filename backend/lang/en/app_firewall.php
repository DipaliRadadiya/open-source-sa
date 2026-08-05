<?php

/*
 * Copy for the Firewall screen — the 8G Firewall section specifically.
 * Plain-language category names; the regex-level detail stays in the code
 * and docs, never the toggle labels a site owner reads.
 */
return [

    'waf' => [
        'modes' => [
            'detect' => 'Just watch, don\'t block',
            'enforce' => 'Actually block',
        ],
        'categories' => [
            'query_string' => 'Bad search terms',
            'request_uri' => 'Bad web addresses',
            'user_agent' => 'Bad visitors',
            'referrer' => 'Bad links',
            'cookie' => 'Bad cookies',
            'method' => 'Bad request types',
        ],
    ],

];

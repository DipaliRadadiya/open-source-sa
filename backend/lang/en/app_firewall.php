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

        'category_descriptions' => [
            'query_string' => 'Blocks requests whose search terms carry SQL, script or file-path tricks — the query string after the ? in a web address.',
            'request_uri' => 'Blocks requests for paths used to probe for installers, backups, config files and known exploits.',
            'user_agent' => 'Blocks requests from scanners, scrapers and exploit tools that identify themselves in the User-Agent header.',
            'referrer' => 'Blocks requests arriving from links that carry injection payloads in the referring address.',
            'cookie' => 'Blocks requests whose cookies contain code or injection payloads rather than ordinary values.',
            'method' => 'Blocks unusual HTTP methods such as TRACE and DEBUG that a normal visitor never sends.',
        ],
    ],

];

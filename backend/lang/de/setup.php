<?php

return [

    'installing' => ':component wird installiert',

    'detail' => [
        'cache_in_use' => 'wird für den Panel-Cache verwendet',
    ],

    'components' => [
        'database' => [
            'title' => 'Datenbank',
            'description' => 'Erforderlich, bevor Sie WordPress oder eine andere Anwendung mit Datenspeicherung installieren.',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'Fügen Sie eine weitere Version hinzu, wenn eine Website sie benötigt.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Mit fnm verwaltet, damit Websites ihre eigene Version festlegen können.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Wird für den Panel-Cache verwendet. Ohne Redis nutzt das Panel die Datenbank — das funktioniert, ist aber langsamer.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Blockiert wiederholte fehlgeschlagene Anmeldungen an SSH und Ihren Websites.',
        ],
    ],

];

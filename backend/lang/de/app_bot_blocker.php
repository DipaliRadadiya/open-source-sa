<?php

return [

    'policies' => [
        'allow_all' => [
            'title' => 'Alle KI-Bots zulassen',
            'description' => 'Kein KI-Crawler wird blockiert.',
        ],
        'block_training' => [
            'title' => 'KI-Trainings-Bots blockieren',
            'description' => 'Stoppt Bots, die Ihre Inhalte zum Trainieren von KI-Modellen sammeln. KI-Suchmaschinen, die Besucher zu Ihnen schicken, wie die ChatGPT-Suche und Perplexity, funktionieren weiterhin.',
        ],
        'block_agents' => [
            'title' => 'KI-Training und KI-Assistenten blockieren',
            'description' => 'Stoppt Trainings-Crawler und KI-Assistenten, die Ihre Seiten einzeln abrufen. KI-Suchmaschinen können Sie weiterhin indexieren, sodass Ihre Erwähnungen in KI-Antworten erhalten bleiben.',
        ],
        'block_all' => [
            'title' => 'Alle KI-Bots blockieren',
            'description' => 'Blockiert jeden bekannten KI-Bot, auch solche, die Ihnen Traffic aus KI-Suchergebnissen bringen.',
        ],
    ],

];

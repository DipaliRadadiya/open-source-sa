<?php

return [

    'policies' => [
        'allow_all' => [
            'title' => 'Autoriser tous les robots IA',
            'description' => 'Aucun robot d\'indexation IA n\'est bloqué.',
        ],
        'block_training' => [
            'title' => 'Bloquer les robots d\'entraînement IA',
            'description' => 'Arrête les robots qui aspirent votre contenu pour entraîner des modèles d\'IA. Les moteurs de recherche IA qui vous envoient des visiteurs, comme la recherche ChatGPT et Perplexity, continuent de fonctionner.',
        ],
        'block_agents' => [
            'title' => 'Bloquer l\'entraînement IA et les assistants IA',
            'description' => 'Arrête les robots d\'entraînement et les assistants IA qui récupèrent vos pages une par une. Les moteurs de recherche IA peuvent toujours vous indexer, vous gardez donc vos citations dans les réponses IA.',
        ],
        'block_all' => [
            'title' => 'Bloquer tous les robots IA',
            'description' => 'Bloque tous les robots IA connus, y compris ceux qui vous envoient du trafic depuis les résultats de recherche IA.',
        ],
    ],

];

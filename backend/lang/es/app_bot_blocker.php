<?php

return [

    'policies' => [
        'allow_all' => [
            'title' => 'Permitir todos los bots de IA',
            'description' => 'No se bloquea ningún rastreador de IA.',
        ],
        'block_training' => [
            'title' => 'Bloquear bots de entrenamiento de IA',
            'description' => 'Detiene los bots que extraen tu contenido para entrenar modelos de IA. Los motores de búsqueda de IA que te envían visitantes, como la búsqueda de ChatGPT y Perplexity, siguen funcionando.',
        ],
        'block_agents' => [
            'title' => 'Bloquear entrenamiento de IA y asistentes de IA',
            'description' => 'Detiene los rastreadores de entrenamiento y los asistentes de IA que solicitan tus páginas una a una. Los motores de búsqueda de IA aún pueden indexarte, así que conservas tus menciones en las respuestas de IA.',
        ],
        'block_all' => [
            'title' => 'Bloquear todos los bots de IA',
            'description' => 'Bloquea todos los bots de IA conocidos, incluidos los que te envían tráfico desde resultados de búsqueda de IA.',
        ],
    ],

];

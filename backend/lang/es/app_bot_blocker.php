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
        'block_all' => [
            'title' => 'Bloquear todos los bots de IA',
            'description' => 'Bloquea todos los bots de IA conocidos, incluidos los que te envían tráfico desde resultados de búsqueda de IA.',
        ],
    ],

];

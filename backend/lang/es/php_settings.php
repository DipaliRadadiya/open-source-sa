<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Tráfico bajo',
            'description' => 'Un par de procesos. Adecuado para la mayoría de sitios pequeños y lo más amable con un servidor pequeño.',
        ],
        'balanced' => [
            'title' => 'Equilibrado',
            'description' => 'Soporta tráfico normal sin reservar memoria que rara vez necesita.',
        ],
        'high' => [
            'title' => 'Tráfico alto',
            'description' => 'Mantiene procesos listos. Úsalo cuando el sitio esté realmente ocupado: reserva memoria se use o no.',
        ],
    ],

    'errors' => [
        'unsupported_stack' => 'Este servidor usa OpenLiteSpeed, que no utiliza pools de PHP-FPM.',
        'already_isolated' => 'Este sitio ya tiene su propio pool de PHP.',
        'not_isolated' => 'Este sitio no está aislado.',
        'write_failed' => 'No se pudo escribir la configuración del pool. No se cambió nada.',
        'config_test_failed' => 'PHP-FPM rechazó la configuración, así que no se aplicó ni se recargó nada. El sitio se sigue sirviendo exactamente igual que antes.',
        'reload_failed' => 'PHP-FPM no se recargó, así que se restauró la configuración anterior.',
        'no_sections' => 'Aquí no se permiten encabezados de sección: iniciarían un segundo pool dentro de este.',
        'function_list' => 'Debe ser una lista de nombres de funciones separados por comas.',
    ],
];

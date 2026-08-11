<?php

return [
    'install_started' => 'Instalando fail2ban. Esto tarda un momento.',

    'bantime' => [
        '10m' => '10 minutos',
        '1h' => '1 hora',
        '1d' => '1 día',
        '1w' => '1 semana',
        'permanent' => 'Permanente',
    ],

    'created_successfully' => '¡Fail2ban configurado correctamente!',
    'test_failed' => 'La prueba de configuración de Fail2ban falló.',
    'already_disabled' => 'Fail2ban ya está desactivado para esta aplicación.',
    'disabled_successfully' => '¡Fail2ban desactivado correctamente!',

    'validation' => [
        'jail_content_required' => 'La configuración de la jaula es obligatoria.',
        'jail_content_string' => 'La configuración de la jaula debe ser texto.',
        'jail_content_max' => 'La configuración de la jaula es demasiado grande (máx. 65535 caracteres).',
        'filter_content_required' => 'La configuración del filtro es obligatoria.',
        'filter_content_string' => 'La configuración del filtro debe ser texto.',
        'filter_content_max' => 'La configuración del filtro es demasiado grande (máx. 65535 caracteres).',
    ],
];

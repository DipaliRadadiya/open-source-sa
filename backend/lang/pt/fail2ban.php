<?php

return [
    'install_started' => 'A instalar o fail2ban. Isto demora um momento.',

    'bantime' => [
        '10m' => '10 minutos',
        '1h' => '1 hora',
        '1d' => '1 dia',
        '1w' => '1 semana',
        'permanent' => 'Permanente',
    ],

    'created_successfully' => 'Fail2ban configurado com sucesso!',
    'test_failed' => 'O teste de configuração do Fail2ban falhou.',
    'already_disabled' => 'O Fail2ban já está desativado para esta aplicação.',
    'disabled_successfully' => 'Fail2ban desativado com sucesso!',

    'validation' => [
        'jail_content_required' => 'A configuração da jail é obrigatória.',
        'jail_content_string' => 'A configuração da jail tem de ser texto.',
        'jail_content_max' => 'A configuração da jail é demasiado grande (máx. 65535 caracteres).',
        'filter_content_required' => 'A configuração do filtro é obrigatória.',
        'filter_content_string' => 'A configuração do filtro tem de ser texto.',
        'filter_content_max' => 'A configuração do filtro é demasiado grande (máx. 65535 caracteres).',
    ],
];

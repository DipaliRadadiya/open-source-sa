<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Tráfego baixo',
            'description' => 'Alguns processos. Adequado à maioria dos sites pequenos e o mais suave para um servidor pequeno.',
        ],
        'balanced' => [
            'title' => 'Equilibrado',
            'description' => 'Aguenta tráfego normal sem reservar memória que raramente precisa.',
        ],
        'high' => [
            'title' => 'Tráfego alto',
            'description' => 'Mantém processos prontos. Use quando o site estiver realmente ocupado — reserva memória seja usada ou não.',
        ],
    ],

    'errors' => [
        'unsupported_stack' => 'Este servidor usa OpenLiteSpeed, que não utiliza pools de PHP-FPM.',
        'already_isolated' => 'Este site já tem o seu próprio pool de PHP.',
        'not_isolated' => 'Este site não está isolado.',
        'write_failed' => 'Não foi possível escrever a configuração do pool. Nada foi alterado.',
        'config_test_failed' => 'O PHP-FPM rejeitou a configuração, por isso não foi aplicada nem recarregada. O site continua a ser servido exatamente como antes.',
        'reload_failed' => 'O PHP-FPM não recarregou, por isso a configuração anterior foi restaurada.',
        'no_sections' => 'Cabeçalhos de secção não são permitidos aqui — iniciariam um segundo pool dentro deste.',
        'function_list' => 'Tem de ser uma lista de nomes de funções separados por vírgulas.',
    ],
];

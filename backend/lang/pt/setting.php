<?php

/*
 * Settings feature strings.
 */

return [
    'reboot_schedule' => [
        'day_of_month' => 'dia do mês',
        'frequency' => [
            'daily' => 'Diariamente',
            'weekly' => 'Semanalmente',
            'monthly' => 'Mensalmente',
        ],
        'day' => [
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
        ],
    ],
    'redis' => [
        'password_applying' => 'A palavra-passe do Redis está a ser aplicada. Recarregue daqui a pouco para confirmar.',
        'policy_evicts_panel_queue' => 'O painel guarda os seus trabalhos em fila neste Redis. Uma política allkeys pode remover silenciosamente um trabalho pendente quando o Redis está cheio, por isso não é permitida aqui. Use noeviction ou uma política volatile.',
    ],
];

<?php

return [
    'not_installed' => 'O fail2ban não está instalado neste servidor.',
    'already_installed' => 'O fail2ban já está instalado.',
    'not_running' => 'O fail2ban está instalado mas não está em execução.',
    'jail_not_active' => 'A prisão :jail não está ativa.',
    'not_banned' => 'Esse endereço IP não está bloqueado neste momento.',
    'lockout_risk' => 'Ativar a prisão SSH pode impedi-lo de aceder a este servidor. Adicione o seu endereço IP à lista de exceções ou confirme que aceita o risco.',
    'ip_ignored' => 'Esse endereço IP está na lista de exceções, pelo que o bloqueio não se manteria.',
    'operation_failed' => 'A operação do fail2ban falhou.',
    'bantime_too_short' => 'O tempo de bloqueio deve ser de pelo menos 60 segundos, ou -1 para um bloqueio permanente.',
];

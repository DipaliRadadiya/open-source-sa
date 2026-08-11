<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Acesso total à shell (bash)',
        'description' => 'A shell padrão do Linux. O utilizador pode iniciar sessão por SSH e executar comandos.',
    ],
    'sh' => [
        'title' => 'Shell básica (sh)',
        'description' => 'Uma shell mínima. Permite iniciar sessão e executar comandos, com menos comodidades que o bash.',
    ],
    'zsh' => [
        'title' => 'Acesso total à shell (zsh)',
        'description' => 'Como o bash, com outras comodidades. O utilizador pode iniciar sessão e executar comandos.',
    ],
    'nologin' => [
        'title' => 'Sem início de sessão',
        'description' => 'O utilizador é dono dos ficheiros e executa o site, mas não pode iniciar sessão. Recomendado para sites que não precisam de acesso por shell.',
    ],
    'false' => [
        'title' => 'Sem início de sessão (legado)',
        'description' => 'O início de sessão é recusado de imediato. Mesmo efeito que «Sem início de sessão»; mantido para servidores que já o usam.',
    ],
];

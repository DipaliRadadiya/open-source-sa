<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Acceso completo al shell (bash)',
        'description' => 'El shell estándar de Linux. El usuario puede iniciar sesión por SSH y ejecutar comandos.',
    ],
    'sh' => [
        'title' => 'Shell básico (sh)',
        'description' => 'Un shell mínimo. El usuario puede iniciar sesión y ejecutar comandos, con menos comodidades que bash.',
    ],
    'zsh' => [
        'title' => 'Acceso completo al shell (zsh)',
        'description' => 'Como bash, con otras comodidades. El usuario puede iniciar sesión y ejecutar comandos.',
    ],
    'nologin' => [
        'title' => 'Sin inicio de sesión',
        'description' => 'El usuario posee sus archivos y ejecuta el sitio, pero no puede iniciar sesión. Recomendado para sitios que no necesitan acceso por shell.',
    ],
    'false' => [
        'title' => 'Sin inicio de sesión (heredado)',
        'description' => 'El inicio de sesión se rechaza de inmediato. Mismo efecto que «Sin inicio de sesión»; se mantiene para servidores que ya lo usan.',
    ],
];

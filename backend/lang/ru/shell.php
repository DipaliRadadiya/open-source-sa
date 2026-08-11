<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Полный доступ к оболочке (bash)',
        'description' => 'Стандартная оболочка Linux. Пользователь может входить по SSH и выполнять команды.',
    ],
    'sh' => [
        'title' => 'Базовая оболочка (sh)',
        'description' => 'Минимальная оболочка. Вход и выполнение команд возможны, но удобств меньше, чем в bash.',
    ],
    'zsh' => [
        'title' => 'Полный доступ к оболочке (zsh)',
        'description' => 'Как bash, но с другими удобствами. Пользователь может входить и выполнять команды.',
    ],
    'nologin' => [
        'title' => 'Без входа',
        'description' => 'Пользователь владеет файлами и запускает сайт, но не может войти. Рекомендуется для сайтов, которым не нужен доступ к оболочке.',
    ],
    'false' => [
        'title' => 'Без входа (устаревший)',
        'description' => 'Вход отклоняется сразу. Тот же эффект, что и «Без входа»; сохранён для серверов, где он уже используется.',
    ],
];

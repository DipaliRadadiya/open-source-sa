<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'Подключено',
        'invalid' => 'Токен недействителен',
        'unknown' => 'Не удалось проверить',
    ],

    'fields' => [
        'token' => 'Токен доступа',
        'host' => 'URL собственного сервера',
        'workspace' => 'Рабочее пространство',
    ],

    'token_help' => [
        'github' => 'Персональный токен доступа с областью «repo».',
        'gitlab' => 'Персональный токен доступа с областями «read_repository» и «read_api». Для gitlab.com оставьте URL пустым.',
        'bitbucket' => 'Токен доступа с ограниченной областью (рабочее пространство, проект или репозиторий). Токен уровня репозитория покажет только этот репозиторий.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Эта учётная запись не имеет доступа к репозиторию. Проверьте, что токен ещё действителен и имеет доступ.',
        'branch_missing' => 'Ветка :branch не существует в этом репозитории.',
    ],
];

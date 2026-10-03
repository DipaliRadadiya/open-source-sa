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
        'github' => 'Персональный токен доступа с областью «repo» (её достаточно и для вебхука деплоя по push).',
        'gitlab' => 'Персональный токен доступа с областями «read_repository» и «read_api». Для деплоя по push используйте «api» вместо «read_api»: GitLab разрешает добавлять и удалять вебхуки только токену с «api».',
        'bitbucket' => 'Токен доступа с ограниченной областью (рабочее пространство, проект или репозиторий). Токен уровня репозитория покажет только этот репозиторий. Для деплоя по push нужны также права на чтение, запись и удаление вебхуков, иначе при отключении вебхук не удастся удалить.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Эта учётная запись не имеет доступа к репозиторию. Проверьте, что токен ещё действителен и имеет доступ.',
        'branch_missing' => 'Ветка :branch не существует в этом репозитории.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Только для self-hosted GitLab — базовый URL вашего экземпляра, например https://git.example.com. Для gitlab.com оставьте пустым.'],
        'bitbucket' => ['workspace' => 'Идентификатор рабочего пространства из вашего URL Bitbucket: bitbucket.org/<workspace>/<repository>. Это не отображаемое имя.'],
    ],
];

<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3-совместимое',
    ],

    'fields' => [
        'name' => 'Отображаемое имя',
        'endpoint' => 'URL конечной точки',
        'region' => 'Регион',
        'bucket' => 'Бакет',
        'prefix' => 'Префикс ключа (необязательно)',
        'access_key' => 'Ключ доступа',
        'secret_key' => 'Секретный ключ',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'Короткая метка, чтобы различать хранилища в списке интеграций.',
        'endpoint' => 'Для AWS оставьте значение по умолчанию. Укажите для MinIO, R2, Backblaze B2, Wasabi и т. д.',
        'region' => 'Регион, в котором находится бакет (требуется только для AWS).',
        'prefix' => 'Необязательный префикс пути внутри бакета (без начального слеша).',
        'access_key' => 'Только для записи — API никогда его не возвращает.',
    ],

    'status' => [
        'connected' => 'Подключено',
        'never_tested' => 'Ещё не проверялось',
        'failed' => 'Последняя проверка не удалась',
    ],

    'test' => [
        'success' => 'Подключение выполнено успешно.',
        'failure' => 'Не удалось подключиться к хранилищу.',
        'invalid_credentials' => 'Хранилище отклонило учётные данные.',
        'unreachable' => 'Не удалось связаться с конечной точкой хранилища.',
        'mismatch' => 'Хранилище вернуло байты, отличные от записанных.',
        'forbidden_host' => 'Этот адрес конечной точки недопустим.',
        'invalid_endpoint' => 'Введите корректный https:// URL конечной точки для бакета.',
    ],

    'delete' => [
        'in_use' => 'Невозможно удалить :name — хранилище всё ещё используется: :applications. Сначала удалите эти цели резервного копирования или измените их ссылку.',
        'and_more' => 'ещё :count',
    ],
];

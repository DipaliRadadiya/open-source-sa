<?php

return [
    'invalid_credentials' => 'Токен :provider отклонён. Проверьте, что он действителен и имеет нужные права.',
    'provider_unreachable' => 'Не удалось связаться с :provider. Повторите попытку через минуту.',
    'unsupported_provider' => 'Провайдер :provider не поддерживается.',
    'invalid_host' => 'Укажите корректный https:// адрес собственного сервера.',
    'blocked_host' => 'Этот адрес недопустим.',

    // Disconnecting an account that applications still deploy with.
    'in_use' => 'Невозможно отключить :name — учётная запись всё ещё используется: :applications. Сначала привяжите эти приложения к другой учётной записи.',
    'and_more' => 'ещё :count',
];

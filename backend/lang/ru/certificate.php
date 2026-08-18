<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Загруженный',
        'self_signed' => 'Самоподписанный',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'Для этого домена недавно выпущено слишком много сертификатов. Лимит сбрасывается через неделю после самого старого — повторите тогда или загрузите сертификат.',
        'rate_limited_failures' => 'Слишком много неудачных попыток для этого домена за последний час. Let\'s Encrypt разрешает пять; подождите час.',
        'unreachable' => 'Запрос проверки не дошёл до этого сервера. Убедитесь, что порт 80 открыт и на нём ничего другого не отвечает.',
        'dns_not_pointing' => 'Домен не указывает на этот сервер. Настройте DNS-запись сюда, дождитесь распространения и повторите.',
        'challenge_not_served' => 'Файл проверки был отдан неверно. Возможно, сайт перенаправляет /.well-known, либо вместо сервера отвечает прокси вроде Cloudflare.',
        'certbot_missing' => 'certbot не установлен на этом сервере.',
        'no_certifiable_domains' => 'Ни один домен этого сайта не готов к выпуску сертификата. Сначала проверьте DNS.',
        'self_sign_failed' => 'Не удалось создать самоподписанный сертификат.',
        'file_missing' => 'Файл сертификата отсутствует на этом сервере. Выпустите его заново.',
        'unknown' => 'Не удалось выпустить сертификат.',
    ],

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'dns_unverified' => 'Ни один домен этого сайта пока не указывает на этот сервер. Добавьте DNS-запись A, дождитесь её распространения и попробуйте снова.',
        'self_signed_warning' => 'Немедленно шифрует трафик и работает с любым доменом, включая тестовые и внутренние. Браузеры покажут предупреждение, так как за него не ручается никто вне этого сервера.',
    ],

];

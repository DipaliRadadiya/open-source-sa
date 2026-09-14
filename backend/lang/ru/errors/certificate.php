<?php

return [

    'no_certifiable_domains' => 'Ни один домен этого приложения не готов к выпуску сертификата. Сначала проверьте DNS.',
    'force_https_without_certificate' => 'Нельзя принудительно включить HTTPS без активного сертификата — сайт перестанет отвечать.',
    'not_pem' => 'Это не похоже на файл PEM. Он должен начинаться с -----BEGIN.',
    'key_mismatch' => 'Закрытый ключ не соответствует сертификату.',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        // The passing verdict. Only the dry-run report needs it: the 422
        // that refuses an issue request never lists a name that passed.
        'ok' => ':domain готов — этот сервер корректно ответил на запрос проверки.',
        'dns_missing' => ':domain вообще не разрешается. Добавьте DNS-запись A, указывающую на этот сервер, и повторите.',
        'dns_not_pointing' => ':domain указывает на :ip — это не этот сервер.',
        'dns_unverifiable' => 'Этот сервер находится за NAT, поэтому панель не может отсюда подтвердить, что :domain указывает на него. Если DNS настроен верно, используйте «Всё равно выпустить» — запрос проверки приходит извне и будет успешным.',
        'behind_proxy' => ':domain указывает на Cloudflare, а не на этот сервер, поэтому запрос проверки не доходит. Приостановите прокси (серое облако) на время выпуска сертификата.',
        'blocked_ip' => ':domain указывает на :ip — это не публичный адрес, для которого можно выпустить сертификат.',
        'unreachable' => 'На порту 80 для :domain никто не ответил. Проверьте, что брандмауэр разрешает порт 80 и веб-сервер запущен.',
        'challenge_redirected' => ':domain перенаправляет запрос проверки вместо ответа на него. Отключите перенаправление с HTTP на HTTPS до выпуска сертификата.',
        'challenge_not_served' => ':domain ответил, но не файлом проверки. Скорее всего, сайт переписывает /.well-known/ — проверьте правила перезаписи.',
        'precheck_failed' => 'Не удалось записать файл проверки на этом сервере, поэтому :domain не был проверен.',
    ],

    // Why a dry run would not start. Not a verdict on any domain — the
    // run never happened, and saying so plainly stops the user reading
    // a refusal as a DNS problem.
    'dry_run' => [
        'issue_in_flight' => 'Для этого сайта сейчас выпускается сертификат. Дождитесь завершения: certbot выполняет только одну операцию за раз, поэтому запущенный сейчас пробный прогон сообщит лишь о конфликте.',
    ],
];

<?php

return [
    'not_installed' => ':addon не установлен на этом сервере.',
    'licence_required' => ':addon не приобретён для этого сервера.',
    'site_not_registered' => 'Этот сайт ещё не зарегистрирован в :addon.',
    'command_failed' => ':addon не удалось выполнить: :message',
    'bad_output' => ':addon ответил так, что панель не смогла прочитать ответ.',
    'timed_out' => ':addon не завершил работу вовремя.',
    'no_system_user' => 'У этого сайта нет системного пользователя.',
    'run_failed' => 'Команда дополнения неожиданно завершилась ошибкой.',
    'unregistered' => 'Регистрация сайта отменена.',
    'option_required' => 'Для этого отчёта требуется :option.',
    'redis_unavailable' => 'Redis не запущен на этом сервере, поэтому настроить Object Cache Pro нельзя.',
    'redis_too_old' => 'Для отдельного входа для каждого сайта Object Cache Pro нужен Redis 6 или новее; на этом сервере Redis :version.',
    'redis_no_password' => 'Сначала задайте пароль Redis: без него любой сайт сможет читать кэш остальных.',
    'redis_failed' => 'Redis не смог создать вход для сайта.',
    'object_cache_not_enabled' => 'Object Cache Pro не включён на этом сайте.',
];

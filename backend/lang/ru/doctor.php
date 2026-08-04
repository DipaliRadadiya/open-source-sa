<?php

return [
    'checks' => [
        'privilege' => 'Привилегированные команды',
        'services' => 'Службы',
        'writable_paths' => 'Доступные для записи пути',
        'database' => 'База данных',
        'health_endpoint' => 'Endpoint состояния',
    ],
    'fixes' => [
        'privilege' => 'Панель не может выполнять команды от root. Проверьте, что в /etc/sudoers.d/ есть разрешение для панели и файл проходит visudo -c.',
        'privilege_disabled' => 'Повышение привилегий отключено, но панель работает не от root. Удалите SERVER_OPS_SUDO=false из .env.',
        'services_missing' => 'Ожидаемый юнит не существует. Задайте PANEL_FRONTEND_SERVICE и PANEL_QUEUE_SERVICE в .env реальными именами.',
        'services_down' => 'Запустите их через systemctl start и посмотрите journalctl -u <юнит>.',
        'writable_paths' => 'Передайте владение учётной записи панели: chown -R <пользователь панели> для указанных путей.',
        'database_unreachable' => 'Проверьте настройки DB_ в .env и работает ли служба базы данных.',
        'database_pending' => 'Выполните php artisan migrate --force. Код обновлён без применения изменений схемы.',
        'health_unreachable' => 'Проверьте, что APP_URL в .env совпадает с адресом панели и что веб-сервер и php-fpm работают.',
        'health_version_mismatch' => 'Выполняемый код и отдаваемая версия различаются. Очистите кэш через php artisan optimize:clear и перезагрузите php-fpm.',
    ],
];

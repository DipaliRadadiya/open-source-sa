<?php

return [

    'install_steps' => [
        'queued' => 'В очереди',
        'checking_conflicts' => 'Проверка конфликтующих СУБД',
        'preparing_repository' => 'Подготовка репозитория пакетов',
        'updating_package_index' => 'Обновление индекса пакетов',
        'preparing' => 'Подготовка пакетов',
        'downloading' => 'Загрузка пакетов',
        'unpacking' => 'Распаковка пакетов',
        'configuring' => 'Настройка пакетов',
        'starting_service' => 'Запуск службы базы данных',
        'verifying_connection' => 'Проверка подключения к базе данных',
        'creating_panel_account' => 'Создание учётной записи базы данных для панели',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'Не удалось создать дамп базы данных. Сообщите в поддержку ссылку ниже.',
        'database_missing' => 'База данных была удалена до запуска экспорта.',
        'worker' => 'Экспорт неожиданно остановился. Возможно, истекло время ожидания — попробуйте снова.',
        'unknown' => 'Экспорт не удался. Сообщите в поддержку ссылку ниже.',
    ],

];

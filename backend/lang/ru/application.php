<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Конструктор блогов и сайтов'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Управляйте базами данных в браузере'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Приватная синхронизация и обмен файлами'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Гибкая система управления контентом'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Онлайн-курсы и обучение'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Автоматизация маркетинга и кампании'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Управление контентом для разработчиков'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Бухгалтерия и счета'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS на файлах — без базы данных'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Интернет-магазин и электронная коммерция'],
        'git' => ['title' => 'Из Git-репозитория', 'tagline' => 'Разверните свой код из GitHub, GitLab или Bitbucket'],
        'php' => ['title' => 'Пустой PHP-сайт', 'tagline' => 'Пустой сайт — загрузите свои файлы'],
        'static' => ['title' => 'Статический сайт', 'tagline' => 'Только HTML, CSS и JavaScript'],
    ],

    'status' => [
        'pending' => 'Ещё не развёрнуто',
        'provisioning' => 'Настройка…',
        'active' => 'Работает',
        'failed' => 'Ошибка настройки',
    ],

    'unavailable' => [
        'php' => 'На этом сервере не установлен PHP.',
        'node' => 'На этом сервере не установлен Node.js.',
    ],

    'git_source' => [
        'account' => 'Из подключённого аккаунта',
        'public_url' => 'Вставить URL публичного репозитория',
    ],

    'fields' => [
        'name' => 'Название',
        'domain' => 'Домен',
        'system_user_id' => 'Системный пользователь',
        'php_version' => 'Версия PHP',
        'node_version' => 'Версия Node.js',
        'app_port' => 'Порт приложения',
        'web_root' => 'Корень сайта',
        'build_command' => 'Команда сборки',
        'start_command' => 'Команда запуска',
        'git_source' => 'Источник',
        'git_account_id' => 'Аккаунт Git',
        'repository' => 'Репозиторий',
        'repository_url' => 'URL репозитория',
        'branch' => 'Ветка',
        'site_title' => 'Название сайта',
        'admin_user' => 'Имя администратора',
        'admin_email' => 'E-mail администратора',
        'admin_password' => 'Пароль администратора',
        'site_language' => 'Язык сайта',
        'table_prefix' => 'Префикс таблиц',
    ],

    'help' => [
        'repository_url' => 'Публичный репозиторий — аккаунт не нужен. Адрес должен начинаться с https://.',
        'build_command' => 'Выполняется после загрузки кода, напр. composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'Создание базы данных',
        'download' => 'Загрузка приложения',
        'extract' => 'Распаковка файлов',
        'configure' => 'Запись конфигурации',
        'install_cli' => 'Установка инструмента настройки',
        'install_app' => 'Запуск установщика',
        'clone' => 'Клонирование репозитория',
        'fetch' => 'Загрузка последнего кода',
        'checkout' => 'Переключение на ветку',
        'build' => 'Выполнение команды сборки',
        'write_credential' => 'Подготовка доступа к git',
        'create_directory' => 'Создание каталога',
        'set_ownership' => 'Назначение владельца',
        'placeholder' => 'Добавление временной страницы',
        'write_config' => 'Запись конфигурации сайта',
        'test_config' => 'Проверка конфигурации',
        'reload' => 'Перезагрузка веб-сервера',
        'worker' => 'Фоновый процесс остановлен',
    ],
];

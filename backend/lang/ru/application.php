<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Основной',
        'alias' => 'Псевдоним',
        'redirect' => 'Перенаправление',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Конструктор блогов и сайтов'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Управляйте базами данных в браузере'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Мониторинг доступности и страницы статуса'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Автоматизация рабочих процессов (лицензия fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Связывайте устройства, API и сервисы'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Форумное ПО — требуется MongoDB'],
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
        'database' => 'Этому приложению нужен :engines, которого нет на этом сервере.',
        'php' => 'На этом сервере не установлен PHP.',
        'node' => 'На этом сервере не установлен Node.js.',
        'web_server' => 'Это приложение пока недоступно на серверах :web_server.',
    ],

    'git_source' => [
        'account' => 'Из подключённого аккаунта',
        'public_url' => 'Вставить URL публичного репозитория',
    ],

    'fields' => [
        'company_name' => 'Название компании',
        'company_email' => 'Эл. почта компании',
        'locale' => 'Локаль',
        'site_name' => 'Название сайта',
        'language' => 'Язык',
        'admin_name' => 'Имя администратора',
        'admin_first_name' => 'Имя администратора',
        'admin_last_name' => 'Фамилия администратора',
        'short_name' => 'Краткое название',
        'shop_name' => 'Название магазина',
        'country' => 'Страна',
        'timezone' => 'Часовой пояс',
        'rendering_type' => 'Тип рендеринга',
        'name' => 'Название',
        'domain' => 'Домен',
        'system_user_id' => 'Системный пользователь',
        'php_version' => 'Версия PHP',
        'node_version' => 'Версия Node.js',
        'app_port' => 'Порт приложения',
        'web_root' => 'Корень сайта',
        'build_command' => 'Команда сборки',
        'deploy_script' => 'Скрипт развёртывания',
        'start_command' => 'Команда запуска',
        'package_manager' => 'Менеджер пакетов',
        'git_source' => 'Источник',
        'git_account_id' => 'Аккаунт Git',
        'repository' => 'Репозиторий',
        'repository_url' => 'URL репозитория',
        'branch' => 'Ветка',
        'site_title' => 'Название сайта',
        'admin_user' => 'Имя администратора',
        'admin_username' => 'Имя администратора',
        'admin_email' => 'E-mail администратора',
        'admin_password' => 'Пароль администратора',
        'site_language' => 'Язык сайта',
        'table_prefix' => 'Префикс таблиц',
        'mailer_name' => 'Имя отправителя',
        'mailer_email' => 'Адрес отправителя',
        'mailer_host' => 'SMTP-хост',
        'mailer_port' => 'SMTP-порт',
        'mailer_username' => 'Имя пользователя SMTP',
        'mailer_password' => 'Пароль SMTP',
    ],

    'help' => [
        'start_command' => 'Входной файл, например «node server.js». Не «npm start»: менеджер пакетов порождает реальный процесс отдельно, поэтому сигналы завершения до него не доходят.',
        'app_port' => 'Если оставить пустым, панель выберет свободный порт.',
        'rendering_type' => 'Серверный рендеринг запускает приложение и проксирует к нему. Два других собираются в файлы, которые веб-сервер отдаёт напрямую — быстрее, и ничего не нужно держать запущенным.',
        'repository_url' => 'Публичный репозиторий — аккаунт не нужен. Адрес должен начинаться с https://.',
        'build_command' => 'Выполняется после загрузки кода, напр. composer install --no-dev',
        'deploy_script' => 'Выполняется после получения кода, от имени пользователя сайта. Оставьте пустым, чтобы использовать команду сборки.',
        'package_manager' => 'Чем устанавливаются и собираются зависимости. Заполняет команду сборки ниже — потом её можно свободно редактировать.',
    ],

    'steps' => [
        'create_database' => 'Создание базы данных',
        'download' => 'Загрузка приложения',
        'extract' => 'Распаковка файлов',
        'configure' => 'Запись конфигурации',
        'install_cli' => 'Установка инструмента настройки',
        'install_app' => 'Запуск установщика',
        'init' => 'Настройка репозитория',
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
        'start_app' => 'Запуск приложения',
        'write_unit' => 'Подготовка службы',
        'restart_app' => 'Перезапуск приложения',
        'harden' => 'Применение настроек безопасности',
        'trust_domain' => 'Разрешение домена',
        'set_password' => 'Установка пароля администратора',
        'worker' => 'Фоновый процесс остановлен',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'out_of_memory' => 'На этом шаге серверу не хватило памяти, и система остановила его. Освободите память или добавьте swap и повторите попытку.',
    ],

    'port_free' => 'Порт :port свободен.',

    'rendering' => [
        'php' => 'PHP-приложение (Laravel, Symfony, обычный PHP)',
        'ssr' => 'Серверный рендеринг (запускает процесс)',
        'csr' => 'Клиентский рендеринг (собирается в файлы)',
        'static' => 'Статический сайт (собирается в файлы)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];

<?php

return [
    'none' => 'нет',
    'yes' => 'да',
    'no' => 'нет',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'по умолчанию',
    'adopted_left_unexpected_path' => 'записанное расположение не из тех, что панель удаляет',
    'adopted_left_other_site' => 'он принадлежит другому сайту или серверу',
    'adopted_left_not_this_site' => 'он обслуживает уже не только этот сайт',
    'adopted_left_unprovable_config' => 'он делает больше, чем обслуживает этот сайт, и панель не может это проверить: блок без имени, сервер по умолчанию, include или alias за пределами сайта',
    'adopted_left_not_regular' => 'это не обычный файл',
    'adopted_left_foreign_link' => 'веб-сервер загружает его через ссылку или копию, которых панель не ожидала',
    'adopted_left_unreadable' => 'его не удалось прочитать',
    'adopted_left_config_test_failed' => 'веб-сервер отклонил конфигурацию без него, поэтому он восстановлен',
    'adopted_left_remove_failed' => 'его не удалось удалить, поэтому всё восстановлено',
    'adopted_left_restore_failed' => 'веб-сервер отклонил конфигурацию без него, и восстановить его не удалось — немедленно проверьте конфигурацию веб-сервера',
    'adopted_left_ols_shared' => 'он зарегистрирован в общей конфигурации OpenLiteSpeed, которую панель не изменяет',
    'adopted_left_worker_shared' => 'это воркер в общем файле программ, владение которым панель не может доказать, поэтому он продолжает работать',
    'adopted_left_worker_systemd' => 'это служба systemd, владение которой панель не может доказать, поэтому она продолжает работать',
    // The activity log's "what changed" line (2026-10-28).
    'on' => 'вкл.',
    'off' => 'выкл.',
    'with_changes' => ':sentence: :changes',
    'unchanged' => ':sentence (ничего не изменено)',
    'change' => ':field изменено с :from на :to',
    'change_secret' => ':field изменён',
    'change_secret_removed' => ':field удалён',
    // The settings group in `setting.updated` ("Updated :group settings").
    'setting_groups' => [
        'general' => '«Общие»',
        'security' => '«Безопасность»',
        'updates' => '«Обновления»',
        'swap' => '«Swap»',
        'redis' => '«Redis»',
    ],
];

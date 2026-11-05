<?php

return [
    'install_started' => 'Устанавливается fail2ban. Это займёт немного времени.',

    'bantime' => [
        '10m' => '10 минут',
        '1h' => '1 час',
        '1d' => '1 день',
        '1w' => '1 неделя',
        'permanent' => 'Бессрочно',
    ],

    'created_successfully' => 'Fail2ban успешно настроен!',
    'test_failed' => 'Проверка конфигурации Fail2ban не пройдена.',
    'rejected' => 'Fail2ban отклонил эту конфигурацию, поэтому ничего не изменено.',
    'already_disabled' => 'Fail2ban уже отключён для этого приложения.',
    'disabled_successfully' => 'Fail2ban успешно отключён!',

    'validation' => [
        'jail_content_required' => 'Конфигурация jail обязательна.',
        'jail_content_string' => 'Конфигурация jail должна быть текстом.',
        'jail_content_max' => 'Конфигурация jail слишком большая (макс. 65535 символов).',
        'filter_content_required' => 'Конфигурация фильтра обязательна.',
        'filter_content_string' => 'Конфигурация фильтра должна быть текстом.',
        'filter_content_max' => 'Конфигурация фильтра слишком большая (макс. 65535 символов).',
        'foreign_jail' => 'Этот jail может настраивать только собственный jail этого приложения. Замените [:section] на [{name}] (станет :name).',
        'foreign_filter' => 'Этот jail может использовать только собственный фильтр этого приложения. Замените filter = :filter на filter = {filter} (станет :name).',
        'disallowed_setting' => 'Параметр «:setting» недопустим в jail приложения. Разрешены: :allowed.',
    ],
    // FB-wp: the default filter is WordPress's.
    'app_default_filter_wordpress_only' => 'У этого приложения пока нет правила для входа, поэтому никто не блокируется. Добавьте правило для его страницы входа.',
];

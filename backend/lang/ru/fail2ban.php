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
    'already_disabled' => 'Fail2ban уже отключён для этого приложения.',
    'disabled_successfully' => 'Fail2ban успешно отключён!',

    'validation' => [
        'jail_content_required' => 'Конфигурация jail обязательна.',
        'jail_content_string' => 'Конфигурация jail должна быть текстом.',
        'jail_content_max' => 'Конфигурация jail слишком большая (макс. 65535 символов).',
        'filter_content_required' => 'Конфигурация фильтра обязательна.',
        'filter_content_string' => 'Конфигурация фильтра должна быть текстом.',
        'filter_content_max' => 'Конфигурация фильтра слишком большая (макс. 65535 символов).',
    ],
];

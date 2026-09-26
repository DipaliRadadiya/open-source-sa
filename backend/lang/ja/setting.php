<?php

/*
 * Settings feature strings.
 */

return [
    'reboot_schedule' => [
        'day_of_month' => '日にち',
        'frequency' => [
            'daily' => '毎日',
            'weekly' => '毎週',
            'monthly' => '毎月',
        ],
        'day' => [
            0 => '日曜日',
            1 => '月曜日',
            2 => '火曜日',
            3 => '水曜日',
            4 => '木曜日',
            5 => '金曜日',
            6 => '土曜日',
        ],
    ],
    'redis' => [
        'password_applying' => 'Redis のパスワードを適用しています。少ししてから再読み込みして確認してください。',
        'policy_evicts_panel_queue' => 'パネルはキュー内のジョブをこの Redis に保存しています。allkeys ポリシーでは Redis が満杯になると保留中のジョブが通知なく削除される可能性があるため、ここでは使用できません。noeviction または volatile ポリシーを使用してください。',
    ],
];

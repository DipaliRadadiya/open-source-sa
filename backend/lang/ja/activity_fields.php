<?php

/*
 * Field names for the activity log's "what changed" line (2026-10-28):
 * "SSH port 22 → 2222". By event type, then `common`; a field with no label
 * reads as its own name with spaces. PHP directives keep their real names.
 */

return [
    'common' => [
        'enabled' => '有効',
        'name' => '名前',
    ],
    'setting' => [
        'hostname' => 'ホスト名',
        'timezone' => 'タイムゾーン',
        'ntp' => '時刻同期（NTP）',
        'port' => 'SSHポート',
        'permit_root_login' => 'rootログイン',
        'password_authentication' => 'パスワードログイン',
        'security_updates_enabled' => '自動セキュリティ更新',
        'auto_reboot' => '更新後の再起動',
        'reboot_time' => '再起動時刻',
        'reboot_with_users' => 'ユーザーのログイン中に再起動',
        'size_human' => 'スワップサイズ',
        'maxmemory' => 'Redisメモリ上限',
        'maxmemory_policy' => 'Redis削除ポリシー',
        'password' => 'Redisパスワード',
        'frequency' => '頻度',
        'hour' => '時',
        'day_of_week' => '曜日',
        'day_of_month' => '日付',
    ],
    'application' => [
        'php_version' => 'PHPバージョン',
        'additional_directives' => '追加ディレクティブ',
        'memory_limit' => 'memory_limit',
        'upload_max_filesize' => 'upload_max_filesize',
        'post_max_size' => 'post_max_size',
        'max_execution_time' => 'max_execution_time',
        'max_input_time' => 'max_input_time',
        'max_input_vars' => 'max_input_vars',
        'session_gc_maxlifetime' => 'session.gc_maxlifetime',
        'pm_type' => 'pm',
        'pm_max_children' => 'pm.max_children',
        'pm_max_requests' => 'pm.max_requests',
        'pm_start_servers' => 'pm.start_servers',
        'pm_min_spare_servers' => 'pm.min_spare_servers',
        'pm_max_spare_servers' => 'pm.max_spare_servers',
        'pm_process_idle_timeout' => 'pm.process_idle_timeout',
        'pm_max_spawn_rate' => 'pm.max_spawn_rate',
        'open_basedir_enabled' => 'open_basedir',
        'open_basedir_paths' => 'open_basedir paths',
        'disable_functions' => 'disable_functions',
        'allow_url_fopen' => 'allow_url_fopen',
        'php_timezone' => 'date.timezone',
        'auto_prepend_file' => 'auto_prepend_file',
    ],
    'cronjob' => [
        'username' => '実行ユーザー',
        'command' => 'コマンド',
        'expression' => 'スケジュール',
        'active' => '有効',
    ],
    'backup' => [
        'storage_destination' => '保存先',
        'type' => 'バックアップ対象',
        'frequency' => '頻度',
        'schedule_time' => '時刻',
        'retention_count' => '保持するバックアップ数',
        'file_excludes' => '除外ファイル',
    ],
    'firewall' => [
        'description' => '説明',
        'port_from' => 'ポート',
        'port_to' => 'ポート範囲の終わり',
        'protocol' => 'プロトコル',
        'action' => 'アクション',
        'source_ip' => '送信元IP',
    ],
];

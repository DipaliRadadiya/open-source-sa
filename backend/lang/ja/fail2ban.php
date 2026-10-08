<?php

return [
    'install_started' => 'fail2ban をインストールしています。少しお待ちください。',

    'bantime' => [
        '10m' => '10 分',
        '1h' => '1 時間',
        '1d' => '1 日',
        '1w' => '1 週間',
        'permanent' => '無期限',
    ],

    'created_successfully' => 'Fail2ban を設定しました。',
    'test_failed' => 'Fail2ban の設定テストに失敗しました。',
    'rejected' => 'Fail2ban がこの設定を拒否したため、何も変更されていません。',
    'already_disabled' => 'このアプリケーションの Fail2ban はすでに無効です。',
    'disabled_successfully' => 'Fail2ban を無効にしました。',

    'validation' => [
        'jail_content_required' => 'jail 設定は必須です。',
        'jail_content_string' => 'jail 設定はテキストである必要があります。',
        'jail_content_max' => 'jail 設定が大きすぎます（最大 65535 文字）。',
        'filter_content_required' => 'フィルター設定は必須です。',
        'filter_content_string' => 'フィルター設定はテキストである必要があります。',
        'filter_content_max' => 'フィルター設定が大きすぎます（最大 65535 文字）。',
        'foreign_jail' => 'このジェイルで設定できるのはこのアプリケーション自身のジェイルだけです。[:section] を [{name}] に置き換えてください（:name になります）。',
        'foreign_filter' => 'このジェイルで使えるのはこのアプリケーション自身のフィルターだけです。filter = :filter を filter = {filter} に置き換えてください（:name になります）。',
        'disallowed_setting' => 'アプリケーションの jail では設定「:setting」は使用できません。使用可能: :allowed。',
    ],
    // FB-wp: the default filter is WordPress's.
    'app_default_filter_wordpress_only' => '既定のルールは WordPress のログイン失敗だけを検出します。:type にはまだそのようなルールがないため、このアプリのログインページ用のルールを追加するまで、この jail は誰もブロックしません。',
];

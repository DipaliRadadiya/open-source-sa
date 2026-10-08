<?php

return [
    'not_installed' => ':addon はこのサーバーにインストールされていません。',
    'licence_required' => ':addon はこのサーバー用に購入されていません。',
    'site_not_registered' => 'このアプリケーションはまだ :addon に登録されていません。',
    'command_failed' => ':addon は実行できませんでした: :message',
    'bad_output' => ':addon の応答をパネルが読み取れませんでした。',
    'timed_out' => ':addon が時間内に完了しませんでした。',
    'no_system_user' => 'このアプリケーションにはシステムユーザーがいません。',
    'run_failed' => 'アドオンのコマンドが予期せず失敗しました。',
    'unregistered' => 'アプリケーションの登録を解除しました。',
    'option_required' => 'このレポートには :option が必要です。',
    'redis_unavailable' => 'このサーバーでは Redis が動作していないため、Object Cache Pro を設定できません。',
    'redis_too_old' => 'Object Cache Pro でアプリケーションごとに別のログインを使うには Redis 6 以上が必要です。このサーバーは Redis :version です。',
    'redis_no_password' => '先に Redis のパスワードを設定してください。未設定だと、どのアプリケーションも他のアプリケーションのキャッシュを読めてしまいます。',
    'redis_failed' => 'Redis がアプリケーション用のログインを作成できませんでした。',
    'object_cache_not_enabled' => 'このアプリケーションでは Object Cache Pro が有効になっていません。',
];

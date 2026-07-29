<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ブログ・ウェブサイト作成'],
        'git' => ['title' => 'Gitリポジトリから', 'tagline' => 'GitHub・GitLab・Bitbucket から自分のコードをデプロイ'],
        'php' => ['title' => '空のPHPサイト', 'tagline' => '空のサイト — ファイルは自分でアップロード'],
        'static' => ['title' => '静的サイト', 'tagline' => 'HTML・CSS・JavaScript のみ'],
    ],

    'status' => [
        'pending' => '未デプロイ',
        'provisioning' => 'セットアップ中…',
        'active' => '稼働中',
        'failed' => 'セットアップ失敗',
    ],

    'unavailable' => [
        'php' => 'このサーバーには PHP がインストールされていません。',
        'node' => 'このサーバーには Node.js がインストールされていません。',
    ],

    'git_source' => [
        'account' => '連携済みアカウントから',
        'public_url' => '公開リポジトリのURLを貼り付け',
    ],

    'fields' => [
        'name' => '名前',
        'domain' => 'ドメイン',
        'system_user_id' => 'システムユーザー',
        'php_version' => 'PHPバージョン',
        'node_version' => 'Node.jsバージョン',
        'app_port' => 'アプリのポート',
        'web_root' => 'ウェブルート',
        'build_command' => 'ビルドコマンド',
        'start_command' => '起動コマンド',
        'git_source' => 'ソース',
        'git_account_id' => 'Gitアカウント',
        'repository' => 'リポジトリ',
        'repository_url' => 'リポジトリURL',
        'branch' => 'ブランチ',
        'site_title' => 'サイトタイトル',
        'admin_user' => '管理者ユーザー名',
        'admin_email' => '管理者メールアドレス',
        'admin_password' => '管理者パスワード',
        'site_language' => 'サイトの言語',
        'table_prefix' => 'テーブル接頭辞',
    ],

    'help' => [
        'repository_url' => '公開リポジトリ — アカウント不要。https:// のアドレスを指定してください。',
        'build_command' => 'コード取得後に実行されます。例: composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'データベースを作成中',
        'download' => 'アプリケーションをダウンロード中',
        'extract' => 'ファイルを展開中',
        'configure' => '設定を書き込み中',
        'install_cli' => 'セットアップツールをインストール中',
        'install_app' => 'インストーラーを実行中',
        'clone' => 'リポジトリをクローン中',
        'fetch' => '最新のコードを取得中',
        'checkout' => 'ブランチをチェックアウト中',
        'build' => 'ビルドコマンドを実行中',
        'write_credential' => 'gitアクセスを準備中',
        'create_directory' => 'ディレクトリを作成中',
        'set_ownership' => '所有者を設定中',
        'placeholder' => '仮ページを作成中',
        'write_config' => 'サイト設定を書き込み中',
        'test_config' => '設定を検証中',
        'reload' => 'ウェブサーバーを再読み込み中',
        'worker' => 'バックグラウンド処理が停止しました',
    ],
];

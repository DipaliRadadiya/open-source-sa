<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => '接続済み',
        'invalid' => 'トークンが無効',
        'unknown' => '確認できません',
    ],

    'fields' => [
        'token' => 'アクセストークン',
        'host' => 'セルフホストURL',
        'workspace' => 'ワークスペース',
    ],

    'token_help' => [
        'github' => '「repo」スコープを持つパーソナルアクセストークン。',
        'gitlab' => '「read_repository」と「read_api」スコープを持つパーソナルアクセストークン。gitlab.com の場合はURLを空のままにしてください。',
        'bitbucket' => 'スコープ付きアクセストークン（ワークスペース・プロジェクト・リポジトリ単位）。リポジトリ単位のトークンではそのリポジトリのみが表示されます。',
    ],
];

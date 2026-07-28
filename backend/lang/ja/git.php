<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
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

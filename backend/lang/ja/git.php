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
        'gitlab' => '「read_repository」と「read_api」スコープを持つパーソナルアクセストークン。',
        'bitbucket' => 'スコープ付きアクセストークン（ワークスペース・プロジェクト・リポジトリ単位）。リポジトリ単位のトークンではそのリポジトリのみが表示されます。',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'このアカウントではこのリポジトリにアクセスできません。トークンが有効で、アクセス権があるか確認してください。',
        'branch_missing' => 'ブランチ :branch はこのリポジトリに存在しません。',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'セルフホスト版GitLabの場合のみ — インスタンスのベースURL（例: https://git.example.com）。gitlab.com の場合は空のままにしてください。'],
        'bitbucket' => ['workspace' => 'BitbucketのURLに含まれるワークスペースID: bitbucket.org/<workspace>/<repository>。表示名ではありません。'],
    ],
];

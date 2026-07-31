<?php

return [

    'instructions' => [
        'github' => 'リポジトリで Settings → Webhooks → Add webhook を開きます。下の URL を貼り付け、Content type を application/json にし、シークレットを Secret に貼り付けて「Just the push event」を選択してください。',
        'gitlab' => 'プロジェクトで Settings → Webhooks → Add new webhook を開きます。下の URL を貼り付け、トリガーに「Push events」を選択してください。そのうえで、GitLab で「Generate signing token」を選び、そのトークンをここに貼り付ける（推奨）か、このパネルのシークレットを GitLab の「Secret token」欄に貼り付けてください。',
        'bitbucket' => 'リポジトリで Repository settings → Webhooks → Add webhook を開きます。下の URL を貼り付け、シークレットを Secret に貼り付けて、トリガーに「Repository push」を選択してください。',
    ],

];

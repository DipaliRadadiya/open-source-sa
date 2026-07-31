<?php

return [

    'instructions' => [
        'github' => 'В репозитории откройте Settings → Webhooks → Add webhook. Вставьте URL, указанный ниже, задайте Content type равным application/json, вставьте секрет в поле Secret и выберите «Just the push event».',
        'gitlab' => 'В проекте откройте Settings → Webhooks → Add new webhook. Вставьте URL, указанный ниже, и выберите триггер «Push events». Затем либо нажмите в GitLab «Generate signing token» и вставьте этот токен здесь (рекомендуется), либо вставьте секрет этой панели в поле GitLab «Secret token».',
        'bitbucket' => 'В репозитории откройте Repository settings → Webhooks → Add webhook. Вставьте URL, указанный ниже, вставьте секрет в поле Secret и выберите триггер «Repository push».',
    ],

];

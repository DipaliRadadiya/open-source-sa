<?php

return [

    'instructions' => [
        'github' => 'No seu repositório, abra Settings → Webhooks → Add webhook. Cole a URL abaixo, defina Content type como application/json, cole o segredo em Secret e selecione «Just the push event».',
        'gitlab' => 'No seu projeto, abra Settings → Webhooks → Add new webhook. Cole a URL abaixo e selecione o gatilho «Push events». Depois, clique em «Generate signing token» no GitLab e cole esse token aqui (recomendado), ou cole o segredo deste painel no campo «Secret token» do GitLab.',
        'bitbucket' => 'No seu repositório, abra Repository settings → Webhooks → Add webhook. Cole a URL abaixo, cole o segredo em Secret e selecione o gatilho «Repository push».',
    ],

];

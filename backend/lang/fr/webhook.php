<?php

return [

    'instructions' => [
        'github' => 'Dans votre dépôt, ouvrez Settings → Webhooks → Add webhook. Collez l\'URL ci-dessous, réglez Content type sur application/json, collez le secret dans Secret et sélectionnez « Just the push event ».',
        'gitlab' => 'Dans votre projet, ouvrez Settings → Webhooks → Add new webhook. Collez l\'URL ci-dessous et sélectionnez le déclencheur « Push events ». Ensuite, soit cliquez sur « Generate signing token » dans GitLab et collez ce jeton ici (recommandé), soit collez le secret de ce panneau dans le champ « Secret token » de GitLab.',
        'bitbucket' => 'Dans votre dépôt, ouvrez Repository settings → Webhooks → Add webhook. Collez l\'URL ci-dessous, collez le secret dans Secret et sélectionnez le déclencheur « Repository push ».',
    ],

];

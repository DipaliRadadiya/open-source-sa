<?php

return [

    'instructions' => [
        'github' => 'Öffnen Sie in Ihrem Repository Settings → Webhooks → Add webhook. Fügen Sie die untenstehende URL ein, setzen Sie Content type auf application/json, fügen Sie das Secret in das Feld Secret ein und wählen Sie „Just the push event“.',
        'gitlab' => 'Öffnen Sie in Ihrem Projekt Settings → Webhooks → Add new webhook. Fügen Sie die untenstehende URL ein und wählen Sie den Trigger „Push events“. Wählen Sie danach entweder in GitLab „Generate signing token“ und fügen Sie dieses Token hier ein (empfohlen), oder fügen Sie das Secret dieses Panels in GitLabs Feld „Secret token“ ein.',
        'bitbucket' => 'Öffnen Sie in Ihrem Repository Repository settings → Webhooks → Add webhook. Fügen Sie die untenstehende URL ein, fügen Sie das Secret in das Feld Secret ein und wählen Sie den Trigger „Repository push“.',
    ],

];

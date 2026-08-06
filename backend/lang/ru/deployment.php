<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'В очереди',
        'running' => 'Выполняется',
        'succeeded' => 'Успешно',
        'failed' => 'Ошибка',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Вручную',
        'webhook' => 'Push',
        'redeploy' => 'Повтор',
        'initial' => 'Первое развёртывание',
    ],

];

<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Синхронизация уже выполняется. Дождитесь её завершения.',
    ],

    'reasons' => [
        'vhost_unreadable' => 'Не удалось прочитать конфигурацию веб-сервера для этого сайта, поэтому он оставлен без изменений.',
        'vhost_unparsed' => 'Сайт обслуживается, но его конфигурация не в том виде, который панель может прочитать. Добавьте его вручную или проверьте файл.',
        'owner_not_tracked' => 'Учётная запись Linux, владеющая сайтом, не управляется панелью. Сначала синхронизируйте системных пользователей.',
        'unreadable_key' => 'Эта строка не является публичным ключом, который панель может прочитать, поэтому она оставлена без изменений. Она всё ещё может давать доступ — проверьте вручную.',
        'discovery_failed' => 'Не удалось прочитать с сервера. Ничего не изменено.',
        'adopt_failed' => 'Найдено на сервере, но панель не смогла создать запись.',
        'requires_system_user' => 'Пропущено: системные пользователи не входили в этот запуск, а они нужны в первую очередь.',
    ],

];

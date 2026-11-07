<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'В OpenLiteSpeed нет слушателя «:listener», к которому можно привязать это приложение. Сначала добавьте его в конфигурации веб-сервера.',

    'reload_failed' => 'Изменение сохранено и прошло проверку конфигурации, но веб-сервер не перезагрузился и продолжает работать с прежними настройками. Перезапустите веб-сервер в разделе «Службы» или проверьте его журнал.',

];

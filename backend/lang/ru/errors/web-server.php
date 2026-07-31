<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'В OpenLiteSpeed нет слушателя «:listener», к которому можно привязать этот сайт. Сначала добавьте его в конфигурации веб-сервера.',

];

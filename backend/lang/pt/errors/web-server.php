<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'O OpenLiteSpeed não tem um listener \":listener\" para associar este site. Adicione um na configuração do servidor web primeiro.',

];

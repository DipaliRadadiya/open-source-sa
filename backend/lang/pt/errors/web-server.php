<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'O OpenLiteSpeed não tem um listener ":listener" para associar esta aplicação. Adicione um na configuração do servidor web primeiro.',

    'reload_failed' => 'A alteração foi guardada e passou no teste de configuração, mas o servidor web não recarregou e continua a usar as definições anteriores. Reinicie o servidor web em Serviços ou consulte o respetivo registo.',

];

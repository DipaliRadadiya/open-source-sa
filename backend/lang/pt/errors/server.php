<?php

return [
    'busy' => 'O servidor está ocupado com outra tarefa do sistema (pode estar a decorrer uma instalação ou atualização de pacotes). Nada foi alterado — tente novamente daqui a pouco.',
    'stale_lock' => 'Um ficheiro de bloqueio residual está a impedir toda a gestão de utilizadores neste servidor. Nada o está a usar — foi deixado por um comando interrompido. Execute `php artisan panel:doctor` para ver os ficheiros a remover.',
    'sudo_denied' => 'A permissão sudo deste servidor é mais antiga do que o painel que nele corre, por isso o comando foi recusado antes de ser executado. Nada foi alterado e tentar de novo não ajuda. Execute `sudo php artisan panel:sudoers` no servidor para reescrever a permissão e tente novamente.',
];

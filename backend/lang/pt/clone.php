<?php

return [
    'status' => [
        'pending' => 'Em fila',
        'running' => 'A clonar',
        'completed' => 'Concluído',
        'failed' => 'Falhou',
    ],

    'current_step' => [
        'provisioning' => 'A criar o site',
        'copying_files' => 'A copiar ficheiros',
        'cloning_database' => 'A clonar a base de dados',
        'starting_process' => 'A iniciar a aplicação',
    ],

    'cloning_errors' => [
        'crashed' => 'A clonagem parou inesperadamente.',
        'failed' => 'A clonagem falhou. Indique a referência ao suporte.',
        'abandoned' => 'Esta clonagem nunca começou e foi libertada. Inicie-a novamente.',
        'copy_failed' => 'A cópia da aplicação falhou no servidor. Indique a referência ao suporte.',
        'setup_failed' => 'Não foi possível preparar a cópia no servidor. Indique a referência ao suporte.',
    ],

    'errors' => [
        'already_running' => 'Esta aplicação já está a ser clonada. Aguarde até terminar e depois inicie outra.',
    ],
];

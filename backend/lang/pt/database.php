<?php

return [

    'install_steps' => [
        'queued' => 'Na fila',
        'checking_conflicts' => 'A verificar conflitos entre motores de base de dados',
        'preparing_repository' => 'A preparar o repositório de pacotes',
        'updating_package_index' => 'A atualizar o índice de pacotes',
        'preparing' => 'A preparar os pacotes',
        'downloading' => 'A transferir os pacotes',
        'unpacking' => 'A descompactar os pacotes',
        'configuring' => 'A configurar os pacotes',
        'starting_service' => 'A iniciar o serviço de base de dados',
        'verifying_connection' => 'A verificar a ligação à base de dados',
        'creating_panel_account' => 'A criar a conta de base de dados do painel',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'O dump da base de dados falhou. Cite a referência abaixo ao suporte.',
        'database_missing' => 'A base de dados foi eliminada antes de a exportação poder ser executada.',
        'worker' => 'A exportação parou inesperadamente. Pode ter expirado — tente novamente.',
        'unknown' => 'A exportação falhou. Cite a referência abaixo ao suporte.',
    ],

];

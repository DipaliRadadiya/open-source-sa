<?php

return [

    'install_steps' => [
        'queued' => 'Na fila',
        'checking_conflicts' => 'A verificar conflitos entre motores de base de dados',
        'preparing_repository' => 'A preparar o repositório de pacotes',
        'waiting_for_package_manager' => 'A aguardar que outra operação de pacotes termine',
        'updating_package_index' => 'A atualizar o índice de pacotes',
        'preparing' => 'A preparar os pacotes',
        'downloading' => 'A transferir os pacotes',
        'unpacking' => 'A descompactar os pacotes',
        'configuring' => 'A configurar os pacotes',
        'starting_service' => 'A iniciar o serviço de base de dados',
        'verifying_cluster' => 'Verificando o cluster de banco de dados',
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

    'import_failed' => [
        'unknown' => 'A importação falhou. Informe ao suporte a referência abaixo.',
        'database_missing' => 'O banco de dados foi excluído antes que a importação pudesse ser executada.',
        'no_account' => 'Este banco de dados não tem um usuário com o qual a importação possa ser executada: um cuja senha o painel conheça e que se conecte a partir deste servidor.',
        'wrong_format' => 'Este arquivo não é um dump que este banco de dados possa importar. MySQL e MariaDB aceitam .sql ou .sql.gz; PostgreSQL aceita .sql, .sql.gz ou um arquivo personalizado do pg_dump; MongoDB aceita um arquivo do mongodump com --archive.',
        'client_command' => 'A linha :line do arquivo contém um comando do psql (um comando com barra invertida, como \\!). Esses comandos podem executar programas no servidor, por isso o arquivo foi recusado. Exporte o banco de dados novamente como SQL simples com o pg_dump.',
        'truncated' => 'O arquivo termina no meio dos dados, então está incompleto. Exporte-o novamente.',
        'unreadable' => 'Não foi possível ler o arquivo como um arquivo do PostgreSQL.',
        'backup_failed' => 'O backup feito antes da importação falhou, então nada foi importado.',
        'load_failed' => 'Não foi possível carregar o arquivo no banco de dados. Informe ao suporte a referência abaixo.',
        'worker' => 'A importação parou inesperadamente. Pode ter excedido o tempo limite.',
        'abandoned' => 'O envio não foi concluído em um dia e foi removido.',
        'restored' => 'O banco de dados foi restaurado a partir do backup automático, então nada foi perdido.',
        'untouched' => 'O banco de dados não foi alterado.',
        'not_restored' => 'O backup automático não pôde ser restaurado sozinho. Ele está na lista de exportações: baixe-o e importe-o para recuperar o banco de dados.',
    ],

];

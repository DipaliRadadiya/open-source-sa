<?php

return [
    'steps' => [
        'dump_database' => 'A exportar a base de dados',
        'archive_files' => 'A criar o arquivo',
        'upload_artifact' => 'A enviar para o armazenamento',
        'verify_artifact' => 'A verificar o envio',
        'prune_old_backups' => 'A remover cópias antigas',
        'rollback' => 'A limpar',
    ],
    'status' => [
        'pending' => 'Em fila',
        'running' => 'A copiar',
        'verifying' => 'A verificar',
        'verified' => 'Concluída',
        'failed' => 'Falhou',
    ],
    'type' => [
        'filesystem' => 'Ficheiros',
        'database' => 'Base de dados',
        'full' => 'Ficheiros e base de dados',
    ],
    'frequency' => [
        'manual' => 'Apenas manual',
        'daily' => 'Diária',
        'weekly' => 'Semanal',
        'monthly' => 'Mensal',
    ],
    'errors' => [
        'restore_unverified' => 'Esta cópia nunca foi verificada, por isso não pode ser restaurada.',
        'restore_no_application' => 'A aplicação desta cópia já não existe.',
        'restore_confirm' => 'Escreva exatamente o domínio da aplicação para confirmar a restauração.',
        'restore_already_running' => 'Já está a decorrer uma restauração para esta aplicação.',
        'restore_no_database' => 'Esta cópia não contém nenhuma base de dados.',
        'restore_no_files' => 'Esta cópia não contém ficheiros.',
        'download_no_artifact' => 'Esta cópia de segurança nunca terminou de ser enviada, por isso não há nenhum arquivo para transferir.',
        'download_no_destination' => 'O destino de armazenamento para onde esta cópia foi enviada já não existe.',
        'download_missing' => 'O arquivo já não está no destino de armazenamento.',
        'not_configured' => 'As cópias de segurança ainda não estão configuradas para esta aplicação.',
        'delete_running' => 'Esta cópia ainda está a decorrer, por isso ainda não pode ser eliminada. Aguarde que termine ou falhe.',
        'delete_artifact' => 'Não foi possível remover o arquivo do destino de armazenamento, por isso nada foi eliminado. Verifique se o destino está acessível e tente novamente.',
        'delete_target_running' => 'Ainda está a decorrer uma cópia desta aplicação. Aguarde que termine antes de desativar as cópias.',
        'delete_target_has_backups' => 'Esta aplicação ainda tem :count cópia(s). Confirme que também devem ser eliminadas, ou elimine-as primeiro.',
        'already_running' => 'Já existe uma cópia de segurança em curso para esta aplicação.',
        'dump_database' => 'Não foi possível exportar a base de dados, por isso nada foi enviado.',
        'archive_files' => 'Não foi possível criar o arquivo — normalmente o servidor ficou sem espaço em disco.',
        'upload_artifact' => 'Não foi possível enviar o arquivo. Verifique se o destino de armazenamento ainda aceita escritas.',
        'verify_artifact' => 'O envio não corresponde ao que foi transmitido, por isso esta cópia não é fiável. Nada de antigo foi removido.',
        'unknown' => 'A cópia de segurança falhou por um motivo desconhecido.',
        'prune_old_backups' => 'Não foi possível remover as cópias antigas. A nova cópia está segura; o armazenamento pode ter mais cópias do que o configurado.',
    ],

    'restore_status' => [
        'pending' => 'Na fila',
        'running' => 'A restaurar',
        'succeeded' => 'Restaurado',
        'failed' => 'A restauração falhou',
    ],

    'restore_steps' => [
        'download_artifact' => 'A descarregar a cópia de segurança',
        'verify_download' => 'A verificar se a cópia está intacta',
        'safety_backup' => 'A copiar primeiro o estado atual',
        'extract_archive' => 'A descompactar a cópia',
        'restore_database' => 'A restaurar a base de dados',
        'swap_files' => 'A colocar os ficheiros',
        'restart_process' => 'A iniciar a aplicação',
    ],

    'restore_errors' => [
        'download_artifact' => 'Não foi possível descarregar a cópia. Nada foi alterado no servidor.',
        'verify_download' => 'A cópia descarregada está incompleta ou corrompida, por isso não foi usada. Nada foi alterado no servidor.',
        'safety_backup' => 'Não foi possível copiar o estado atual, por isso a restauração foi interrompida. Nada foi sobrescrito.',
        'extract_archive' => 'Não foi possível descompactar a cópia. Nada foi alterado no servidor.',
        'restore_database' => 'Não foi possível restaurar a base de dados. A cópia feita antes contém o estado anterior.',
        'swap_files' => 'Não foi possível colocar os ficheiros. A diretoria anterior do site foi restaurada.',
        'restart_process' => 'Os ficheiros e a base de dados foram restaurados mas a aplicação não arrancou. Verifique os registos.',
        'missing_backup' => 'A cópia foi removida antes de a restauração poder começar.',
        'crashed' => 'A restauração parou inesperadamente. Verifique a cópia de segurança antes de tentar de novo.',
        'unknown' => 'A restauração falhou por um motivo desconhecido.',
    ],

    'cloning' => [
        'provisioning' => 'A criar o site',
        'copying_files' => 'A copiar ficheiros',
        'cloning_database' => 'A clonar a base de dados',
        'starting_process' => 'A iniciar a aplicação',
    ],

    'cloning_errors' => [
        'crashed' => 'A clonagem parou inesperadamente.',
    ],

    'schedule_time' => 'Hora agendada',
];

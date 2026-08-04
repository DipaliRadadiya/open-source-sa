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
        'not_configured' => 'As cópias de segurança ainda não estão configuradas para esta aplicação.',
        'already_running' => 'Já existe uma cópia de segurança em curso para esta aplicação.',
        'dump_database' => 'Não foi possível exportar a base de dados, por isso nada foi enviado.',
        'archive_files' => 'Não foi possível criar o arquivo — normalmente o servidor ficou sem espaço em disco.',
        'upload_artifact' => 'Não foi possível enviar o arquivo. Verifique se o destino de armazenamento ainda aceita escritas.',
        'verify_artifact' => 'O envio não corresponde ao que foi transmitido, por isso esta cópia não é fiável. Nada de antigo foi removido.',
        'unknown' => 'A cópia de segurança falhou por um motivo desconhecido.',
        'prune_old_backups' => 'Não foi possível remover as cópias antigas. A nova cópia está segura; o armazenamento pode ter mais cópias do que o configurado.',
    ],
];

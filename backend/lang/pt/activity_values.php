<?php

return [
    'none' => 'nenhum',
    'yes' => 'sim',
    'no' => 'não',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'predefinido',
    'adopted_left_unexpected_path' => 'a localização registrada não é uma que o painel removeria',
    'adopted_left_other_site' => 'pertence a outro site ou ao servidor',
    'adopted_left_not_this_site' => 'não serve mais apenas este site',
    'adopted_left_unprovable_config' => 'faz mais do que servir este site de uma forma que o painel não consegue verificar: um bloco sem nome, o servidor padrão, um include ou um alias fora do site',
    'adopted_left_not_regular' => 'não é um arquivo comum',
    'adopted_left_foreign_link' => 'o servidor web o carrega por um link ou cópia que o painel não esperava',
    'adopted_left_unreadable' => 'não foi possível lê-lo',
    'adopted_left_config_test_failed' => 'o servidor web rejeitou a configuração sem ele, por isso foi restaurado',
    'adopted_left_remove_failed' => 'não foi possível removê-lo, por isso tudo foi restaurado',
    'adopted_left_restore_failed' => 'o servidor web rejeitou a configuração sem ele e não foi possível restaurá-lo — verifique agora a configuração do servidor web',
    'adopted_left_ols_shared' => 'está registrado na configuração compartilhada do OpenLiteSpeed, que o painel não edita',
    'adopted_left_worker_shared' => 'é um worker em um arquivo de programas compartilhado que o painel não consegue provar que é dele, por isso continua em execução',
    'adopted_left_worker_systemd' => 'é um serviço systemd que o painel não consegue provar que é dele, por isso continua em execução',
    // The activity log's "what changed" line (2026-10-28).
    'on' => 'ligado',
    'off' => 'desligado',
    'with_changes' => ':sentence: :changes',
    'unchanged' => ':sentence (nada alterado)',
    'change' => ':field alterado de :from para :to',
    'change_secret' => ':field alterada',
    'change_secret_removed' => ':field removida',
];

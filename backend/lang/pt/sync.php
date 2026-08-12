<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Já existe uma sincronização em curso. Aguarde que termine antes de iniciar outra.',
    ],

    'reasons' => [
        'vhost_unreadable' => 'Não foi possível ler a configuração do servidor web deste site, por isso ficou intacto.',
        'vhost_unparsed' => 'Este site está a ser servido, mas a sua configuração não tem um formato que o painel consiga ler. Adote-o manualmente ou verifique o ficheiro.',
        'owner_not_tracked' => 'A conta Linux dona deste site não é gerida pelo painel. Sincronize primeiro os utilizadores do sistema.',
        'unreadable_key' => 'Esta linha não é uma chave pública que o painel consiga ler, por isso ficou intacta. Pode continuar a conceder acesso — verifique-a manualmente.',
        'discovery_failed' => 'Não foi possível ler do servidor. Nada foi alterado.',
        'adopt_failed' => 'Encontrado no servidor, mas o painel não conseguiu criar um registo.',
        'requires_system_user' => 'Ignorado porque os utilizadores do sistema não faziam parte desta execução e são necessários primeiro.',
    ],

];

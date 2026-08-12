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
        'firewall_direction_unsupported' => 'Esta é uma regra de saída. O painel só gere regras de entrada, e registá-la aqui aplicá-la-ia na direção errada.',
        'firewall_action_unsupported' => 'Esta regra limita ou rejeita em vez de permitir ou negar. O painel não tem equivalente, e registá-la como um simples allow ou deny descreveria mal o que o servidor faz.',
        'firewall_app_profile' => 'Esta regra usa um perfil de aplicação em vez de uma porta. As portas por trás podem mudar quando o pacote é atualizado, por isso importar os números de hoje seria uma fotografia a fazer-se passar pela regra.',
        'panel_infrastructure' => 'Isto é o próprio painel, não um site que ele possa alojar. Deixado intacto de propósito.',
        'outside_panel_layout' => 'Este site não está organizado da forma como o painel gere sites, por isso não pode ser adotado sem mover os seus ficheiros. Continua a ser servido — nada foi alterado.',
        'vhost_unreadable' => 'Não foi possível ler a configuração do servidor web deste site, por isso ficou intacto.',
        'vhost_unparsed' => 'Este site está a ser servido, mas a sua configuração não tem um formato que o painel consiga ler. Adote-o manualmente ou verifique o ficheiro.',
        'owner_not_tracked' => 'A conta Linux dona deste site não é gerida pelo painel. Sincronize primeiro os utilizadores do sistema.',
        'unreadable_key' => 'Esta linha não é uma chave pública que o painel consiga ler, por isso ficou intacta. Pode continuar a conceder acesso — verifique-a manualmente.',
        'discovery_failed' => 'Não foi possível ler do servidor. Nada foi alterado.',
        'adopt_failed' => 'Encontrado no servidor, mas o painel não conseguiu criar um registo.',
        'requires_system_user' => 'Ignorado porque os utilizadores do sistema não faziam parte desta execução e são necessários primeiro.',
    ],

];

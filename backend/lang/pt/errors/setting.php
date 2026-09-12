<?php

return [
    'operation_failed' => 'A alteração das configurações falhou no servidor.',
    'group_unavailable' => 'Esse grupo de configurações não está disponível neste servidor.',
    'security_updates_unavailable' => 'O unattended-upgrades não está instalado neste servidor, por isso o painel não tem nada para executar. Instale o pacote unattended-upgrades e tente novamente.',
    'security_updates_in_progress' => 'Já está a correr uma atualização de segurança.',
    'no_ssh_key' => 'Adicione uma chave SSH antes de desativar a autenticação por senha, ou você pode se bloquear.',
    'redis_credential_unusable' => 'O painel não consegue aceder ao Redis com a palavra-passe que tem guardada, por isso não a pode alterar. O Redis está a funcionar mas rejeita as credenciais do painel — corrija REDIS_PASSWORD no .env do painel para a palavra-passe que o Redis exige e tente novamente.',
    'env_not_writable' => 'O painel não consegue escrever o seu próprio ficheiro .env, pelo que a nova palavra-passe do Redis não pôde ser registada. Corrija primeiro as permissões do ficheiro — caso contrário o painel perderia o acesso ao Redis.',
    'swap_in_use' => 'A memória de troca está em uso e não foi possível desativá-la. O servidor não tem memória livre suficiente para recuperar o que está em swap — liberte memória e tente novamente.',
];

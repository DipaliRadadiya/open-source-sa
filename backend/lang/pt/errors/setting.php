<?php

return [
    'operation_failed' => 'A alteração das configurações falhou no servidor.',
    'group_unavailable' => 'Esse grupo de configurações não está disponível neste servidor.',
    'no_ssh_key' => 'Adicione uma chave SSH antes de desativar a autenticação por senha, ou você pode se bloquear.',
    'env_not_writable' => 'O painel não consegue escrever o seu próprio ficheiro .env, pelo que a nova palavra-passe do Redis não pôde ser registada. Corrija primeiro as permissões do ficheiro — caso contrário o painel perderia o acesso ao Redis.',
    'swap_in_use' => 'A memória de troca está em uso e não foi possível desativá-la. O servidor não tem memória livre suficiente para recuperar o que está em swap — liberte memória e tente novamente.',
];

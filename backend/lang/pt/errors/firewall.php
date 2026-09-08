<?php

return [
    'operation_failed' => 'A operação do firewall falhou no servidor.',
    'duplicate' => 'Já existe uma regra de firewall com estas configurações.',
    'protected_rule' => 'A regra da porta :ports é gerida pelo painel — removê-la pode cortar o acesso a este servidor. Não pode ser removida enquanto o firewall estiver ativado. Desative primeiro o firewall ou adicione a sua própria regra ao lado.',
    'protected_rule_edit' => 'A regra da porta :ports é gerida pelo painel — alterá-la pode cortar o acesso a este servidor. Enquanto o firewall estiver ativado, apenas a sua descrição pode ser alterada. Desative o firewall para a editar ou adicione a sua própria regra ao lado.',
    'invalid_source' => 'A origem deve ser um endereço IP ou intervalo CIDR válido.',
    'ssh_lockout' => 'Esta é a única regra que permite SSH na porta :port. Removê-la bloquearia o seu acesso a este servidor. Adicione primeiro outra regra para essa porta ou desative o firewall.',
];

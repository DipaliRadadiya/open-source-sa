<?php

return [
    'operation_failed' => 'A operação do firewall falhou no servidor.',
    'duplicate' => 'Já existe uma regra de firewall com estas configurações.',
    'conflict' => 'Já existe uma regra para a porta :ports com o mesmo protocolo e origem (:action). O ufw mantém apenas uma regra por porta, protocolo e origem, então adicionar a oposta a substituiria. Edite essa regra em vez disso.',
    'protected_rule' => 'A regra da porta :ports é gerida pelo painel — removê-la pode cortar o acesso a este servidor. Não pode ser removida enquanto o firewall estiver ativado. Desative primeiro o firewall ou adicione a sua própria regra ao lado.',
    'protected_rule_edit' => 'A regra da porta :ports é gerida pelo painel — alterá-la pode cortar o acesso a este servidor. Enquanto o firewall estiver ativado, apenas a sua descrição pode ser alterada. Desative o firewall para a editar ou adicione a sua própria regra ao lado.',
    'invalid_source' => 'A origem deve ser um endereço IP ou intervalo CIDR válido.',
    'ssh_lockout' => 'Esta é a única regra que permite SSH na porta :port. Removê-la bloquearia o seu acesso a este servidor. Adicione primeiro outra regra para essa porta ou desative o firewall.',
    'unmanaged_not_found' => 'Essa regra já não está no firewall do servidor. Atualize a página para ver as regras atuais.',
    'range_needs_protocol' => 'Um intervalo de portas precisa de um só protocolo. Escolha TCP ou UDP, ou adicione duas regras.',
];

<?php

return [
    'apt_cache' => ['label' => 'Cache de pacotes', 'description' => 'Arquivos .deb baixados que não são mais necessários.', 'note' => 'Remove apenas os downloads em cache em /var/cache/apt/archives; os pacotes instalados continuam funcionando.'],
    'apt_orphans' => ['label' => 'Pacotes não utilizados', 'description' => 'Pacotes instalados automaticamente e kernels antigos que não são mais necessários.', 'note' => 'Remove pacotes dos quais nada depende mais e kernels obsoletos; o kernel em execução é mantido.'],
    'journal' => ['label' => 'Journal do sistema', 'description' => 'Entradas do journal do systemd mais antigas que o período de retenção.', 'note' => 'Reduz o histórico antigo do journal além do período de retenção; as entradas recentes são mantidas.'],
    'rotated_logs' => ['label' => 'Logs rotacionados', 'description' => 'Arquivos de log comprimidos e rotacionados antigos em /var/log.', 'note' => 'Exclui arquivos já rotacionados (.gz / .1 / .old) em /var/log; os logs atuais não são tocados.'],
    'service_logs' => ['label' => 'Logs de serviços', 'description' => 'Esvazia os arquivos de log atuais dos serviços em execução (mantidos, não excluídos).', 'note' => 'Esvazia os arquivos de log atuais (truncados para 0 bytes); os serviços continuam escrevendo neles, nada é excluído.'],
    'tmp' => ['label' => 'Arquivos temporários', 'description' => 'Arquivos antigos em /tmp e /var/tmp.', 'note' => 'Exclui arquivos em /tmp e /var/tmp mais antigos que o período de retenção.'],
];

<?php

return [
    'apt_cache' => ['label' => 'Cache de pacotes', 'description' => 'Arquivos .deb baixados que não são mais necessários.'],
    'apt_orphans' => ['label' => 'Pacotes não utilizados', 'description' => 'Pacotes instalados automaticamente e kernels antigos que não são mais necessários.'],
    'journal' => ['label' => 'Journal do sistema', 'description' => 'Entradas do journal do systemd mais antigas que o período de retenção.'],
    'rotated_logs' => ['label' => 'Logs rotacionados', 'description' => 'Arquivos de log comprimidos e rotacionados antigos em /var/log.'],
    'service_logs' => ['label' => 'Logs de serviços', 'description' => 'Esvazia os arquivos de log atuais dos serviços em execução (mantidos, não excluídos).'],
    'tmp' => ['label' => 'Arquivos temporários', 'description' => 'Arquivos antigos em /tmp e /var/tmp.'],
];

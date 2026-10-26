<?php

return [
    'apt_cache' => ['label' => 'Cache de pacotes', 'description' => 'Arquivos de pacotes .deb baixados.', 'note' => '"Excluir tudo" esvazia o cache; um período de retenção remove apenas downloads mais antigos. Os pacotes instalados continuam funcionando.'],
    'apt_orphans' => ['label' => 'Pacotes não utilizados', 'description' => 'Pacotes instalados automaticamente e kernels antigos que não são mais necessários.', 'note' => 'Remove pacotes dos quais nada depende mais e kernels obsoletos; o kernel em execução é mantido.'],
    'journal' => ['label' => 'Journal do sistema', 'description' => 'Histórico do journal do systemd.', 'note' => 'Remove as entradas do journal mais antigas que o período escolhido; "Excluir tudo" remove todas.'],
    'rotated_logs' => ['label' => 'Logs rotacionados', 'description' => 'Arquivos de log antigos compactados e rotacionados em /var/log.', 'note' => 'Exclui os arquivos rotacionados (.gz / .1 / .old) mais antigos que o período escolhido, ou todos com "Excluir tudo". Os logs atuais não são alterados.'],
    'service_logs' => ['label' => 'Logs de serviços', 'description' => 'Arquivos de log atuais dos serviços em execução.', 'note' => '"Excluir tudo" os esvazia (os arquivos continuam e os serviços seguem escrevendo). Um período de retenção esvazia apenas os logs em que nada foi escrito nesse tempo.'],
    'site_logs' => ['label' => 'Logs das aplicações', 'description' => 'Logs de acesso e de erros de cada aplicação hospedada, com suas cópias rotacionadas.', 'note' => 'Exclui as cópias rotacionadas mais antigas que o período escolhido e esvazia os logs em que nada foi escrito nesse tempo. "Excluir tudo" esvazia cada log e exclui cada cópia: o histórico de visitas e erros é perdido.'],
    'tmp' => ['label' => 'Arquivos temporários', 'description' => 'Arquivos em /tmp e /var/tmp.', 'note' => 'Exclui arquivos mais antigos que o período escolhido. Mesmo "Excluir tudo" mantém os arquivos escritos na última hora, para não quebrar instalações e envios em andamento.'],
    // The keep choice in words, for the activity log.
    'keep_days' => ['default' => 'retenção padrão', 'delete_all' => 'tudo excluído', 'keep' => ':days dia mantido|:days dias mantidos'],
];

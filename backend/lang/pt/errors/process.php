<?php

return [
    'not_found' => 'Este processo já não está em execução.',
    'protected' => 'Este processo pertence a um serviço protegido e não pode ser parado aqui.',
    'database' => 'Este é um servidor de banco de dados. Pará-lo deixaria o banco de dados de todos os sites fora do ar, por isso ele não pode ser parado aqui. Para reiniciá-lo, use a tela Serviços.',
    'kernel_thread' => 'As threads do kernel não podem ser paradas.',
    'self' => 'O painel não pode parar o seu próprio processo.',
    'kill_failed' => 'Não foi possível parar o processo.',
    'still_running' => 'O processo ainda está em execução. Ele pode estar terminando ou ignorando o pedido. Use Forçar parada para encerrá-lo agora.',
    'still_running_after_kill' => 'O processo ainda está em execução após Forçar parada. Provavelmente está preso esperando um disco ou um compartilhamento de rede, e nenhum sinal pode encerrá-lo até essa espera terminar.',
];

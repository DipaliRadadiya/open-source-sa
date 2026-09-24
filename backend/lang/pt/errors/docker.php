<?php

return [
    'not_a_docker_server' => 'Este servidor não aloja contentores, pelo que não tem redes nem volumes Docker.',
    'invalid_name' => 'Um nome pode usar letras, números, pontos, traços e sublinhados, e tem de começar por uma letra ou número.',
    'network_exists' => 'Já existe uma rede chamada :name.',
    'volume_exists' => 'Já existe um volume chamado :name.',
    'network_built_in' => ':name é uma das redes do próprio Docker. O Docker recria-a ao reiniciar, e removê-la quebraria todos os contentores deste servidor.',
    'network_in_use' => 'A rede :name ainda tem contentores ligados: :containers. Pare-os ou desligue-os primeiro.',
    'network_used_by_sites' => 'Estes sites estão configurados para se juntar à rede :name: :sites. Altere primeiro a rede deles — removê-la agora impediria que arrancassem.',
    'volume_in_use' => 'O volume :name ainda é usado por :count contentor(es). Pare-os primeiro — remover um volume em uso apaga dados que algo ainda está a escrever.',
    'network_create_failed' => 'Não foi possível criar a rede. Referência :reference.',
    'network_remove_failed' => 'Não foi possível remover a rede. Referência :reference.',
    'volume_create_failed' => 'Não foi possível criar o volume. Referência :reference.',
    'volume_remove_failed' => 'Não foi possível remover o volume. Referência :reference.',
];

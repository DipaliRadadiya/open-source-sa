<?php

return [
    'not_a_docker_server' => 'Este servidor não aloja contentores, pelo que não tem redes nem volumes Docker.',
    'invalid_name' => 'Um nome pode usar letras, números, pontos, traços e sublinhados, e tem de começar por uma letra ou número.',
    'network_exists' => 'Já existe uma rede chamada :name.',
    'volume_exists' => 'Já existe um volume chamado :name.',
    'network_built_in' => ':name é uma das redes do próprio Docker. O Docker recria-a ao reiniciar, e removê-la quebraria todos os contentores deste servidor.',
    'network_in_use' => 'A rede :name ainda tem contentores ligados: :containers. Pare-os ou desligue-os primeiro.',
    'network_used_by_sites' => 'Estas aplicações estão configuradas para se juntar à rede :name: :sites. Altere primeiro a rede delas — removê-la agora impediria que arrancassem.',
    'volume_in_use' => 'O volume :name ainda é usado por contentores (:count). Pare-os primeiro — remover um volume em uso apaga dados que algo ainda está a escrever.',
    'volume_in_use_by' => 'O volume :name ainda é usado por :containers. Pare-os primeiro — remover um volume em uso apaga dados que algo está a escrever.',
    'volume_used_by_sites' => 'Estas aplicações montam o volume :name: :sites. Remova primeiro a montagem — apagá-lo agora destrói os dados que lá guardam.',
    'network_create_failed' => 'Não foi possível criar a rede. Referência :reference.',
    'network_remove_failed' => 'Não foi possível remover a rede. Referência :reference.',
    'volume_create_failed' => 'Não foi possível criar o volume. Referência :reference.',
    'volume_remove_failed' => 'Não foi possível remover o volume. Referência :reference.',
    'registry_deleted' => 'A credencial do registo foi eliminada. As aplicações que a usavam passarão a obter as imagens anonimamente.',
    'registry_credential_unwritable' => 'Não foi possível escrever a credencial do registo no disco, pelo que o Docker nunca foi consultado. Referência :reference.',
    'database_start_failed' => 'Não foi possível iniciar a base de dados (:step). Não ficou nada atrás — nenhum registo, nenhum contentor e nenhuma porta reservada.',
    'database_version_unknown' => 'Esse motor não tem a versão :version. Escolha uma das que o painel oferece para :engine.',
    'database_deleted' => 'A base de dados foi removida.',
];

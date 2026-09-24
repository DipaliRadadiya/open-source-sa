<?php

return [
    'not_a_docker_server' => 'Este servidor no aloja contenedores, así que no tiene redes ni volúmenes de Docker.',
    'invalid_name' => 'Un nombre puede usar letras, números, puntos, guiones y guiones bajos, y debe empezar por una letra o un número.',
    'network_exists' => 'Ya existe una red llamada :name.',
    'volume_exists' => 'Ya existe un volumen llamado :name.',
    'network_built_in' => ':name es una de las redes propias de Docker. Docker la recrea al reiniciar, y eliminarla rompería todos los contenedores de este servidor.',
    'network_in_use' => 'La red :name todavía tiene contenedores conectados: :containers. Deténlos o desconéctalos primero.',
    'network_used_by_sites' => 'Estos sitios están configurados para unirse a la red :name: :sites. Cambia primero su red: si la eliminas ahora, dejarán de arrancar.',
    'volume_in_use' => 'El volumen :name aún lo usan :count contenedor(es). Deténlos primero: eliminar un volumen en uso borra datos que algo sigue escribiendo.',
    'network_create_failed' => 'No se pudo crear la red. Referencia :reference.',
    'network_remove_failed' => 'No se pudo eliminar la red. Referencia :reference.',
    'volume_create_failed' => 'No se pudo crear el volumen. Referencia :reference.',
    'volume_remove_failed' => 'No se pudo eliminar el volumen. Referencia :reference.',
];

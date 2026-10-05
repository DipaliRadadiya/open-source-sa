<?php

return [
    'operation_failed' => 'La operación del firewall falló en el servidor.',
    'duplicate' => 'Ya existe una regla de firewall con esta configuración.',
    'conflict' => 'Ya existe una regla para el puerto :ports con el mismo protocolo y origen (:action). ufw mantiene una sola regla por puerto, protocolo y origen, así que añadir la opuesta la reemplazaría. Edita esa regla en su lugar.',
    'protected_rule' => 'La regla del puerto :ports la gestiona el panel: eliminarla podría cortar el acceso a este servidor. No se puede eliminar mientras el firewall está activado. Desactive primero el firewall o añada su propia regla junto a ella.',
    'protected_rule_edit' => 'La regla del puerto :ports la gestiona el panel: modificarla podría cortar el acceso a este servidor. Mientras el firewall está activado solo se puede cambiar su descripción. Desactive el firewall para editarla o añada su propia regla junto a ella.',
    'invalid_source' => 'El origen debe ser una dirección IP o un rango CIDR válido.',
    'ssh_lockout' => 'Esta es la única regla que permite SSH en el puerto :port. Eliminarla le bloquearía el acceso a este servidor. Añada primero otra regla para ese puerto o desactive el firewall.',
    'unmanaged_not_found' => 'Esa regla ya no está en el firewall del servidor. Actualiza la página para ver las reglas actuales.',
];

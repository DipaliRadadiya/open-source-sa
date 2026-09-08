<?php

return [
    'operation_failed' => 'La operación del firewall falló en el servidor.',
    'duplicate' => 'Ya existe una regla de firewall con esta configuración.',
    'protected_rule' => 'La regla del puerto :ports la gestiona el panel: eliminarla podría cortar el acceso a este servidor. No se puede eliminar mientras el firewall está activado. Desactive primero el firewall o añada su propia regla junto a ella.',
    'protected_rule_edit' => 'La regla del puerto :ports la gestiona el panel: modificarla podría cortar el acceso a este servidor. Mientras el firewall está activado solo se puede cambiar su descripción. Desactive el firewall para editarla o añada su propia regla junto a ella.',
    'invalid_source' => 'El origen debe ser una dirección IP o un rango CIDR válido.',
    'ssh_lockout' => 'Esta es la única regla que permite SSH en el puerto :port. Eliminarla le bloquearía el acceso a este servidor. Añada primero otra regla para ese puerto o desactive el firewall.',
];

<?php

return [
    'operation_failed' => 'La operación del firewall falló en el servidor.',
    'duplicate' => 'Ya existe una regla de firewall con esta configuración.',
    'protected_rule' => 'Esta regla está protegida y no se puede eliminar mientras el firewall está activado.',
    'invalid_source' => 'El origen debe ser una dirección IP o un rango CIDR válido.',
    'ssh_lockout' => 'Esta es la única regla que permite SSH en el puerto :port. Eliminarla le bloquearía el acceso a este servidor. Añada primero otra regla para ese puerto o desactive el firewall.',
];

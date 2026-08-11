<?php

return [
    'create_failed' => 'No se pudo crear el usuario del sistema en el servidor.',
    'delete_failed' => 'No se pudo eliminar el usuario del sistema en el servidor.',
    'has_applications' => 'Este usuario del sistema aún posee una o más aplicaciones y no se puede eliminar.',
    'reserved_username' => 'Este nombre de usuario está reservado y no se puede usar.',
    'duplicate_public_key' => 'Esta clave SSH ya ha sido añadida.',
    'invalid_public_key' => 'El valor proporcionado no es una clave pública SSH válida.',
    'password_failed' => 'No se pudo establecer la contraseña del usuario del sistema.',
    'sudo_failed' => 'No se pudo actualizar el acceso sudo del usuario del sistema.',
    'shell_failed' => 'No se pudo cambiar el shell del usuario del sistema.',
    'ssh_failed' => 'No se pudo actualizar el acceso SSH del usuario del sistema.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'El acceso SSH necesita un shell con el que el usuario pueda iniciar sesión. El shell de este usuario rechaza el inicio de sesión, así que SSH se conectaría y se desconectaría de inmediato. Elige primero un shell de inicio de sesión.',
    'shell_needs_ssh_off' => 'Este usuario tiene acceso SSH y el shell elegido rechaza el inicio de sesión: SSH se conectaría y se desconectaría de inmediato. Desactiva primero el acceso SSH o elige un shell de inicio de sesión.',
];

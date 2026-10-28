<?php

return [
    'create_failed' => 'No se pudo crear el usuario del sistema en el servidor.',
    'delete_failed' => 'No se pudo eliminar el usuario del sistema en el servidor.',
    'has_applications' => 'Este usuario del sistema aún posee una o más aplicaciones y no se puede eliminar.',
    'reserved_username' => 'Este nombre de usuario está reservado y no se puede usar.',
    'duplicate_public_key' => 'Esta clave SSH ya ha sido añadida.',
    'invalid_public_key' => 'El valor proporcionado no es una clave pública SSH válida.',
    'dsa_public_key' => 'Esta es una clave DSA. Ubuntu ya no acepta claves DSA para iniciar sesión por SSH, así que nunca funcionaría. Usa una clave Ed25519 (recomendada) o RSA 4096.',
    'weak_rsa_public_key' => 'Esta clave RSA es demasiado corta. Usa al menos :bits bits; se recomienda RSA 4096 o una clave Ed25519.',
    'ssh_key_exists' => ':username ya tiene esta clave SSH, como ":name".',
    'password_failed' => 'No se pudo establecer la contraseña del usuario del sistema.',
    'sudo_failed' => 'No se pudo actualizar el acceso sudo del usuario del sistema.',
    'shell_failed' => 'No se pudo cambiar el shell del usuario del sistema.',
    'ssh_failed' => 'No se pudo actualizar el acceso SSH del usuario del sistema.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'El acceso SSH necesita un shell con el que el usuario pueda iniciar sesión. El shell de este usuario rechaza el inicio de sesión, así que SSH se conectaría y se desconectaría de inmediato. Elige primero un shell de inicio de sesión.',
    'shell_needs_ssh_off' => 'Este usuario tiene acceso SSH y el shell elegido rechaza el inicio de sesión: SSH se conectaría y se desconectaría de inmediato. Desactiva primero el acceso SSH o elige un shell de inicio de sesión.',

    'password_control_characters' => 'La contraseña no puede contener saltos de línea ni otros caracteres de control.',
    'shell_not_installed' => 'El shell :shell no está instalado en este servidor. Instálalo primero o elige otro shell.',
    'username_taken_on_server' => 'Ya existe en el servidor un usuario o grupo con este nombre. Elige otro nombre de usuario.',
    'has_processes' => ':username sigue conectado o ejecutando algo (por ejemplo, una sesión SSH abierta o un trabajo en curso). Cierre su sesión o espere a que termine, o vuelva a eliminarlo con «Terminar sesiones» activado, que las termina primero.',

    // Container login (RC-28).
    'container_login_failed' => 'No se pudo activar el inicio de sesión en el contenedor para el usuario del sistema.',
    'container_login_unsupported' => 'La configuración SSH de este servidor no aplica el inicio de sesión en el contenedor (su sshd_config no incluye sshd_config.d), así que no se activó.',
    'container_login_no_owner' => 'Esta aplicación no tiene usuario del sistema.',
    'container_login_confirm' => '{1} :username también es dueño de :count aplicación más. Su SSH y SFTP abrirán solo este contenedor. Confirme para continuar.|[2,*] :username también es dueño de :count aplicaciones más. Su SSH y SFTP abrirán solo este contenedor. Confirme para continuar.',
    'container_login_needs_key' => 'El inicio de sesión con contraseña está desactivado en este servidor, así que :username necesita una clave SSH. Añada una para activar el inicio de sesión en el contenedor.',
];

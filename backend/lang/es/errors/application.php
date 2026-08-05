<?php

return [
    'primary_domain_not_removable' => 'No se puede eliminar el dominio principal. Haga principal otro dominio primero.',
    'unsupported_web_server' => 'El panel no puede escribir la configuración del sitio para :web_server.',
    'no_web_server' => 'ningún servidor web detectado',
    'provision_failed' => 'La configuración del sitio falló en el paso «:step».',
    'not_a_git_application' => 'La aplicación no es un despliegue de git, así que no hay nada que descargar.',
    'no_database_engine' => 'No hay ningún motor de base de datos disponible. Instala y configura MySQL o MariaDB antes de crear esta aplicación.',
    'no_process' => '\":name\" no ejecuta un proceso propio.',
    'process_failed' => 'No se pudo :action la aplicación. Indica la referencia al soporte.',
    'no_port_available' => 'No hay puertos libres entre :from y :to. Libera uno o amplía el rango.',

    'webhook_not_a_git_application' => 'El despliegue automático solo está disponible para aplicaciones desplegadas desde un repositorio git.',

    'permissions_fix_failed' => 'No se pudieron restablecer los permisos de archivo en el servidor.',

    'unsafe_path' => 'Esa ruta no está permitida.',
    'file_too_large' => 'Ese archivo es demasiado grande para abrirlo aquí. Usa SFTP para archivos grandes.',
    'file_not_text' => 'Ese archivo no parece texto y no se puede abrir aquí.',
    'file_operation_failed' => 'La operación de archivo falló en el servidor.',

    'file_not_archive' => 'Aquí solo se pueden extraer archivos .zip y .tar.gz.',
    'archive_unreadable' => 'No se pudo leer ese archivo. Puede estar dañado.',
    'archive_empty' => 'Ese archivo no contiene nada.',
    'archive_too_many_entries' => 'Ese archivo tiene demasiados archivos para extraerlo aquí.',
    'archive_too_large' => 'Ese archivo sería demasiado grande una vez extraído.',
    'archive_has_symlink' => 'Ese archivo contiene un enlace simbólico, lo cual no está permitido.',
    'archive_unsafe_entry' => 'Ese archivo contiene una ruta de archivo que no está permitida.',

    'path_exists' => 'Ya existe algo en esa ruta.',
    'cannot_delete_root' => 'No se puede eliminar la carpeta raíz del sitio.',

];

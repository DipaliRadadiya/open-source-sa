<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login necesita HTTPS. Por HTTP sin cifrar, el token de acceso —y la sesión de administrador que otorga— viajan en texto plano, así que cualquiera por el camino se convierte en administrador de este sitio. Emita primero un certificado en la pestaña Dominios y SSL.',
    'multisite_unsupported' => 'Esta es una red multisitio de WordPress. Por ahora Magic Login solo admite instalaciones de un solo sitio: en una red, los administradores que se listan aquí no pueden acceder al administrador de red, así que entraría con menos acceso del que parece.',
    'not_an_administrator' => 'Esa cuenta no es administradora de este sitio. La lista puede haber cambiado desde que se abrió: cierre Magic Login y vuelva a intentarlo para actualizarla.',
    'list_failed' => 'No se pudieron listar los administradores de este sitio. Puede que WordPress no esté instalado en esta ruta o que wp-cli no se haya podido ejecutar.',
    'list_unreadable' => 'WordPress devolvió algo ilegible en lugar de la lista de administradores. Lo más probable es que el sitio esté mostrando un aviso de PHP: revise su registro de errores.',
    'mint_failed' => 'No se pudo guardar el token de acceso de un solo uso en la base de datos de este sitio.',
    'loader_failed' => 'No se pudo escribir el archivo auxiliar de Magic Login en este sitio.',
];

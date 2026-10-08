<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'O Magic Login precisa de HTTPS. Em HTTP simples, o token de início de sessão — e a sessão de administrador que ele dá — atravessam a rede em texto simples, por isso qualquer pessoa pelo caminho torna-se administradora desta aplicação. Emita primeiro um certificado no separador Domínios e SSL.',
    'multisite_unsupported' => 'Esta é uma rede multissite do WordPress. Por agora o Magic Login só trata instalações de site único: numa rede, os administradores aqui listados não alcançam a administração da rede, pelo que entraria com menos acesso do que parece.',
    'not_an_administrator' => 'Essa conta não é administradora desta aplicação. A lista pode ter mudado desde que foi aberta — feche o Magic Login e tente novamente para a atualizar.',
    'list_failed' => 'Não foi possível listar os administradores desta aplicação. O WordPress pode não estar instalado neste caminho, ou o wp-cli não conseguiu executar aqui.',
    'list_unreadable' => 'O WordPress devolveu algo ilegível em vez da lista de administradores. A aplicação está muito provavelmente a imprimir um aviso de PHP — consulte o seu registo de erros.',
    'mint_failed' => 'Não foi possível guardar o token de utilização única na base de dados desta aplicação.',
    'loader_failed' => 'Não foi possível escrever o ficheiro auxiliar do Magic Login nesta aplicação.',
];

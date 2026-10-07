<?php

return [
    'registry_status' => [
        'connected' => 'Ligado',
        'never_tested' => 'Ainda não testado',
        'failed' => 'O último teste falhou',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'O registo recusou este nome de utilizador e este token.',
        'unreachable' => 'Não foi possível contactar o registo a partir deste servidor. Verifique o endereço e se está acessível por HTTPS.',
        'unknown' => 'O registo recusou a ligação e não disse porquê. A resposta do Docker está no registo de operações do servidor.',
    ],

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'Esse não é um nome de imagem que o Docker aceite. Use um nome como nginx, usememos/memos ou ghcr.io/owner/app, opcionalmente seguido de :versão. Os nomes de repositório são em minúsculas.',
        'not_found' => 'Não foi encontrada nenhuma imagem chamada :image. Verifique a ortografia — ou, se for privada, escolha a credencial de registo que a consegue ler.',
        'tag_not_found' => 'A imagem :image não tem a versão :tag. Escolha uma da lista de versões.',
        'credential_rejected' => 'A credencial de registo escolhida não consegue ler :image. Verifique se a imagem existe e se o token a pode descarregar.',
        'registry_unreachable' => 'Não foi possível contactar o registo :registry a partir deste servidor, por isso a imagem não foi verificada. Pode indicar a porta manualmente.',
        'rate_limited' => 'O Docker Hub está a limitar a frequência dos pedidos deste servidor. Tente novamente dentro de alguns minutos ou indique a porta manualmente.',
        'blocked_host' => 'O painel não se liga a esse endereço de registo. Endereços de loopback e link-local são recusados.',
        'warning_required_env' => 'Esta imagem precisa destas definições para arrancar: :keys.',
        'warning_empty_env' => 'Estas definições estão vazias na imagem. Preencha-as apenas se a documentação da imagem o pedir: :keys.',
        'warning_no_build' => 'Esta imagem não tem compilação para :architecture, por isso não vai funcionar neste servidor.',
        'warning_large' => 'Esta imagem tem :size para descarregar. O primeiro arranque vai demorar.',
        'warning_no_port' => 'Esta imagem não indica em que porta escuta. Introduza a porta indicada na documentação da imagem.',
        'warning_several_ports' => 'Esta imagem escuta em várias portas (:ports). Foi escolhida a porta :port para o site; altere-a se a documentação disser outra coisa.',
    ],
];

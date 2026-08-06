<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Apenas observar, não bloquear',
            'enforce' => 'Bloquear de facto',
        ],
        'categories' => [
            'query_string' => 'Termos de pesquisa maliciosos',
            'request_uri' => 'Endereços web maliciosos',
            'user_agent' => 'Visitantes maliciosos',
            'referrer' => 'Ligações maliciosas',
            'cookie' => 'Cookies maliciosos',
            'method' => 'Tipos de pedido maliciosos',
        ],

        'category_descriptions' => [
            'query_string' => 'Bloqueia pedidos cujos termos de pesquisa contêm truques de SQL, script ou caminho de ficheiro — a cadeia de consulta após o ? num endereço web.',
            'request_uri' => 'Bloqueia pedidos de caminhos usados para procurar instaladores, cópias de segurança, ficheiros de configuração e exploits conhecidos.',
            'user_agent' => 'Bloqueia pedidos de scanners, recolectores e ferramentas de exploit que se identificam no cabeçalho User-Agent.',
            'referrer' => 'Bloqueia pedidos vindos de ligações que transportam cargas de injeção no endereço de origem.',
            'cookie' => 'Bloqueia pedidos cujos cookies contêm código ou cargas de injeção em vez de valores normais.',
            'method' => 'Bloqueia métodos HTTP invulgares como TRACE e DEBUG que um visitante normal nunca envia.',
        ],
    ],

];

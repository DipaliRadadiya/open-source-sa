<?php

it('renders the API reference as HTML at a public URL', function () {
    $response = $this->get('/docs/api-reference');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/html');
    // markdown headings become HTML and known content is present
    $response->assertSee('System Users', escape: false);
    $response->assertSee('<h1', escape: false);
});

it('serves the raw markdown', function () {
    $response = $this->get('/docs/api-reference.md');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/markdown');
    expect($response->getContent())->toStartWith('# ServerAvatar OSS — API Reference');
});

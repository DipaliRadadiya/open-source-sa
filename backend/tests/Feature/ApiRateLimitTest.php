<?php

it('rate limits unauthenticated requests at 20 per minute', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->getJson('/api/basic-info')->assertOk();
    }

    $this->getJson('/api/basic-info')->assertStatus(429);
});

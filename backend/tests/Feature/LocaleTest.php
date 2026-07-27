<?php

use App\Models\User;

it('returns English messages by default', function () {
    User::factory()->admin()->create();

    $response = $this->postJson('/api/auth/register', [
        'name' => 'X', 'username' => 'x', 'password' => 'Password123', 'password_confirmation' => 'Password123',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('message', 'Registration is closed. An administrator already exists; ask an admin to create your account.');
});

it('returns Spanish messages when Accept-Language is es', function () {
    User::factory()->admin()->create();

    $response = $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/auth/register', [
            'name' => 'X', 'username' => 'x', 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ]);

    $response->assertForbidden()
        ->assertJsonPath('message', 'El registro está cerrado. Ya existe un administrador; pide a un administrador que cree tu cuenta.');
});

it('ignores unsupported Accept-Language values and falls back to default', function () {
    $response = $this->withHeader('Accept-Language', 'xx-not-a-locale')
        ->getJson('/api/basic-info');

    $response->assertOk();
});

it('returns Spanish validation error messages when Accept-Language is es', function () {
    $response = $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/auth/register', [
            'name' => '', 'username' => '', 'password' => '',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.');
});

it('only advertises locales that have translation files', function () {
    $response = $this->getJson('/api/basic-info');

    $response->assertOk()->assertJsonPath('basic_info.locales_available', ['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi']);
});

it('returns German validation error messages when Accept-Language is de', function () {
    $response = $this->withHeader('Accept-Language', 'de')
        ->postJson('/api/auth/register', ['name' => '', 'username' => '', 'password' => '']);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Das Feld Name ist erforderlich.');
});

it('translates the registration-closed message for every advertised locale', function () {
    User::factory()->admin()->create();

    $expected = [
        'de' => 'Die Registrierung ist geschlossen. Es existiert bereits ein Administrator; bitten Sie einen Administrator, Ihr Konto zu erstellen.',
        'fr' => 'Les inscriptions sont fermées. Un administrateur existe déjà ; demandez à un administrateur de créer votre compte.',
        'pt' => 'O registro está fechado. Já existe um administrador; peça a um administrador para criar a sua conta.',
        'ja' => '登録は締め切られています。すでに管理者が存在します。管理者にアカウントの作成を依頼してください。',
        'ru' => 'Регистрация закрыта. Администратор уже существует; попросите администратора создать вашу учётную запись.',
        'hi' => 'पंजीकरण बंद है। एक व्यवस्थापक पहले से मौजूद है; अपना खाता बनाने के लिए किसी व्यवस्थापक से कहें।',
    ];

    foreach ($expected as $locale => $message) {
        // username varies per locale so each request lands in its own
        // throttle:login bucket (keyed by username+IP) — otherwise the 6th
        // call trips the 5/min limiter and returns 429 instead of 403.
        $this->withHeader('Accept-Language', $locale)
            ->postJson('/api/auth/register', [
                'name' => 'X', 'username' => "x_{$locale}", 'password' => 'Password123', 'password_confirmation' => 'Password123',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', $message);
    }
});

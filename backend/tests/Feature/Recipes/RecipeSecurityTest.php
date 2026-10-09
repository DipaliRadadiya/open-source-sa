<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\RecipeSchema;
use App\Services\Recipes\RecipeTemplate;
use App\Services\Recipes\RecipeTemplateLinter;
use App\Services\Recipes\RecipeValueRules;

it('refuses every forbidden compose key and docker socket binding', function ($line) {
    $directory = base_path('tests/Fixtures/recipes/valid/demo_single');
    $data = json_decode(file_get_contents($directory.'/recipe.json'), true);
    $source = file_get_contents($directory.'/compose.yml.tpl')."\n".$line;
    try {
        RecipeTemplateLinter::lint($data, RecipeTemplate::fromString($source, 'demo_single'));
        $this->fail('Forbidden key accepted');
    } catch (InvalidRecipeException $e) {
        expect($e->code)->toBe('template_forbidden_key');
    }
})->with(['privileged: true', 'cap_add: []', 'devices: []', 'pid: host', 'ipc: host', 'userns_mode: host', 'security_opt: []', 'network_mode: host', 'cgroup_parent: root', 'build: .', '- /var/run/docker.sock:/socket']);

it('refuses newline and carriage return in every context value', function ($name, $value) {
    foreach (["\n", "\r"] as $newline) {
        expect(fn () => RecipeValueRules::assertValid($name, $value.$newline))->toThrow(RecipeRenderException::class);
    }
})->with([
    ['project', 'sv-app-1'], ['app_port', '80'], ['container_port', '80'], ['image.app', 'ghost:5'],
    ['memory_limit', '512m'], ['db_memory_limit', '512m'], ['cpu_limit', '1.5'], ['url', 'https://x.test'],
    ['domain', 'x.test'], ['site_root', '/home/user'], ['secret.PASSWORD', 'abcdefgh'],
    ['volume.data', 'sv-app-1_data'], ['input.admin_email', 'admin@x.test'], ['input.admin_username', 'admin'],
]);

it('rejects schema ambiguity and unrecognized claim references', function () {
    $directory = base_path('tests/Fixtures/recipes/valid/demo_claim');
    $data = json_decode(file_get_contents($directory.'/recipe.json'), true);
    $cases = [];
    $d = $data;
    $d['first_run']['credentials'][0]['value'] = 'literal';
    $cases[] = $d;
    $d = $data;
    $d['tagline']['xx'] = 'unknown';
    $cases[] = $d;
    $d = $data;
    $d['secrets'][] = $d['secrets'][0];
    $cases[] = $d;
    $d = $data;
    $d['services'][] = ['name' => 'other', 'role' => 'app'];
    $cases[] = $d;
    $d = $data;
    $d['hook'] = 'App\\Services\\Recipes\\Hooks\\Missing';
    $cases[] = $d;
    $d = $data;
    $d['after_install']['fields']['email'] = '{{ input.unknown }}';
    $cases[] = $d;
    $d = $data;
    $d['after_install']['fields']['email'] = '{{ secret.UNKNOWN }}';
    $cases[] = $d;
    $d = $data;
    $d['after_install']['fields']['email'] = '{{ app.name|base64 }}';
    $cases[] = $d;
    $d = $data;
    $d['description'] = [];
    $cases[] = $d;
    $d = $data;
    $d['icon'] = 123;
    $cases[] = $d;
    $d = $data;
    $d['memory_floor'] = 512;
    $cases[] = $d;
    foreach ($cases as $case) {
        expect(fn () => RecipeSchema::check($case, $directory))->toThrow(InvalidRecipeException::class);
    }
});

it('accepts tagged registry ports and pinned digests but rejects implicit tags', function () {
    expect(RecipeValueRules::imageRefValid('registry.example.test:5000/team/app:1.2'))->toBeTrue();
    expect(RecipeValueRules::imageRefValid('ghost@sha256:'.str_repeat('a', 64)))->toBeTrue();
    foreach (['registry.example.test:5000/team/app', 'ghost:5 ', 'ghost:5"', 'ghost:5$HOME'] as $ref) {
        expect(RecipeValueRules::imageRefValid($ref))->toBeFalse();
    }
});

it('never discloses the rejected value in render errors', function () {
    $value = "private-value\nprivileged: true";
    try {
        RecipeTemplate::fromString('{{ secret.A }}', 'demo')->render(['secret.A' => $value]);
        $this->fail('Injection accepted');
    } catch (RecipeRenderException $e) {
        expect($e->field)->toBe('secret.A')->and($e->getMessage())->not->toContain($value)->not->toContain('private-value');
    }
});

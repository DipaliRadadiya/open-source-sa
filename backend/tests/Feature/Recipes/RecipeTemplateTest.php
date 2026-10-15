<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\RecipeTemplate;

it('preserves comment whitespace and final newline', function () {
    expect(RecipeTemplate::fromString("name: x\n    {{-- x --}}\n    ports:\n", 'demo')->render([]))->toBe("name: x\n    \n    ports:\n");
    expect(RecipeTemplate::fromString("{{-- multi\nline --}}\nname: x", 'demo')->render([]))->toBe('name: x');
});
it('removes directive lines and false blocks', function () {
    $t = RecipeTemplate::fromString("name: x\n{{#if cpu_limit}}\ncpus: {{ cpu_limit }}\n{{/if}}\nend\n", 'demo');
    expect($t->render(['cpu_limit' => null]))->toBe("name: x\nend\n");
    expect($t->render(['cpu_limit' => '1.5']))->toBe("name: x\ncpus: 1.5\nend\n");
    expect($t->conditions())->toBe(['cpu_limit']);
    expect($t->placeholders())->toBe(['cpu_limit']);
});
it('rejects malformed grammar', function ($source) {
    expect(fn () => RecipeTemplate::fromString($source, 'demo'))->toThrow(InvalidRecipeException::class);
})->with(['{{#if cpu_limit}}', '{{/if}}', "{{#if cpu_limit}}\n{{#if domain}}\n{{/if}}\n{{/if}}", '{{ expression + 1 }}']);
it('rejects unknown values filters and newline injection without revealing values', function () {
    foreach (['{{ foo|bar }}'] as $source) {
        expect(fn () => RecipeTemplate::fromString($source, 'demo')->render(['foo' => 'safe']))->toThrow(RecipeRenderException::class, 'foo');
    }
});
it('rejects unknown name', fn () => expect(fn () => RecipeTemplate::fromString('{{ foo }}', 'demo')->render([]))->toThrow(RecipeRenderException::class));
it('base64 encodes and backstops injected values', function () {
    expect(RecipeTemplate::fromString('{{ secret.A|base64 }}', 'demo')->render(['secret.A' => 'abcdefgh']))->toBe(base64_encode('abcdefgh'));
    expect(fn () => RecipeTemplate::fromString('{{ domain }}', 'demo')->render(['domain' => "x.test\nprivileged: true"]))->toThrow(RecipeRenderException::class);
    expect(RecipeTemplate::fromString("{{-- header --}}\nname: {{ project }}\n", 'demo')->render(['project' => 'sv-app-1']))->toBe("name: sv-app-1\n");
});

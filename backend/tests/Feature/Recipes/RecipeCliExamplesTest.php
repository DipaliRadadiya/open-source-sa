<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Recipes\RecipeSchema;

/**
 * `cli_examples` (RC-28): optional commands a recipe offers on the
 * Command-line access card, each run in the app's main container. They are
 * pasted into a terminal as part of an `ssh` one-liner, so each must be one
 * short line.
 */
function cliRecipe(mixed $examples): array
{
    $data = json_decode((string) file_get_contents(resource_path('recipes/openclaw/recipe.json')), true);
    $data['cli_examples'] = $examples;

    return $data;
}

it('accepts a recipe without cli_examples, and one with a valid list', function () {
    $data = json_decode((string) file_get_contents(resource_path('recipes/openclaw/recipe.json')), true);
    unset($data['cli_examples']);

    RecipeSchema::check($data, resource_path('recipes/openclaw'));
    RecipeSchema::check(cliRecipe([['label' => 'Run migrations', 'command' => "php artisan migrate --force && echo 'done'"]]), resource_path('recipes/openclaw'));

    expect(true)->toBeTrue();
});

it('refuses cli_examples that are not short single-line label and command pairs', function (mixed $examples) {
    expect(fn () => RecipeSchema::check(cliRecipe($examples), resource_path('recipes/openclaw')))
        ->toThrow(InvalidRecipeException::class);
})->with([
    'not a list' => ['openclaw devices list'],
    'no command' => [[['label' => 'List']]],
    'no label' => [[['command' => 'ls']]],
    'empty command' => [[['label' => 'List', 'command' => '']]],
    'not a string' => [[['label' => 'List', 'command' => ['ls']]]],
    'newline' => [[['label' => 'List', 'command' => "ls\nrm -rf /"]]],
    'carriage return' => [[['label' => 'List', 'command' => "ls\rx"]]],
    'newline in label' => [[['label' => "List\nmore", 'command' => 'ls']]],
    'unknown key' => [[['label' => 'List', 'command' => 'ls', 'user' => 'root']]],
    'too long' => [[['label' => 'List', 'command' => str_repeat('a', 301)]]],
    'too many' => [array_fill(0, 11, ['label' => 'List', 'command' => 'ls'])],
]);

it('ships OpenClaw\'s browser approval commands, in the form RC-17 verified', function () {
    $recipe = app(RecipeRegistry::class)->find('openclaw');

    expect($recipe->cliExamples)->toBe([
        ['label' => 'List pending browsers', 'command' => 'openclaw devices list'],
        ['label' => 'Approve a browser', 'command' => 'openclaw devices approve <requestId>'],
    ])
        // Container login replaced the sudo docker exec route in its notes.
        ->and(implode("\n", $recipe->notes))->not->toContain('sudo docker exec')
        ->and($recipe->notes[0])->toContain("app's system user");
});

it('gives every other recipe no examples yet', function () {
    $with = collect(app(RecipeRegistry::class)->all())
        ->filter(fn ($recipe) => $recipe->cliExamples !== [])
        ->map(fn ($recipe) => $recipe->slug)
        ->values()
        ->all();

    expect($with)->toBe(['openclaw', 'paperclip']);
});

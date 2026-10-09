<?php

namespace App\Services\Recipes;

use App\Models\Application;
use App\Services\Recipes\Exceptions\RecipeRenderException;

final class RecipeRenderer
{
    public function __construct(private RecipeContext $context) {}

    public function render(Recipe $recipe, Application $application, string $url, array $secrets, string $documentRoot): string
    {
        $values = $this->context->build($recipe, $application, $url, $secrets, $documentRoot);
        $complex = $recipe->complexSecretKeys();
        $names = $recipe->template->activePlaceholders($values);
        $optionalInputs = array_column(array_filter($recipe->inputs, fn ($input) => ! $input['required']), 'name');

        foreach ($recipe->template->conditions() as $name) {
            // Omitted optional input may switch a block off, but may never become
            // an empty scalar. Active placeholders above still validate it.
            if (str_starts_with($name, 'input.')
                && in_array(substr($name, 6), $optionalInputs, true)
                && in_array($values[$name] ?? null, [null, ''], true)) {
                continue;
            }
            $names[] = $name;
        }
        $names = array_unique($names);

        foreach ($names as $name) {
            if (! array_key_exists($name, $values)) {
                throw new RecipeRenderException($name);
            }

            RecipeValueRules::assertValid(
                $name,
                $values[$name],
                str_starts_with($name, 'secret.') && in_array(substr($name, 7), $complex, true) ? 'complex' : null,
                $name === 'input.admin_email' ? 'email' : null,
            );
        }

        return $recipe->template->render($values);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Recipes\RecipeValueRules;
use Illuminate\Console\Command;

final class ValidateRecipes extends Command
{
    protected $signature = 'recipes:validate';

    protected $description = 'Validate shipped recipe metadata and compose templates';

    public function handle(RecipeRegistry $registry): int
    {
        try {
            $recipes = $registry->all();
            if (! $recipes) {
                $this->line('No recipes found in '.$registry->path());

                return 0;
            }
            foreach ($recipes as $recipe) {
                foreach ([null, '1.5'] as $cpu) {
                    $values = ['project' => 'sv-app-1', 'app_port' => 20001, 'container_port' => $recipe->containerPort, 'memory_limit' => '512m', 'db_memory_limit' => '512m', 'cpu_limit' => $cpu, 'url' => 'https://example.test', 'domain' => 'example.test', 'site_root' => '/home/u/site/public_html'];
                    foreach ($recipe->images as $role => $image) {
                        $values['image.'.$role] = $image['ref'];
                    }
                    foreach ($recipe->volumes as $role => $path) {
                        $values['volume.'.$role] = 'sv-app-1_'.$role;
                    }
                    foreach ($recipe->secrets as $s) {
                        $values['secret.'.$s['key']] = $s['complexity'] === 'complex' ? 'Aa1!'.str_repeat('a', 28) : str_repeat('a', 32);
                    }
                    foreach ($recipe->inputs as $input) {
                        $values['input.'.$input['name']] = $input['name'] === 'admin_email' ? 'admin@example.test' : 'admin';
                    }
                    foreach ($values as $name => $value) {
                        RecipeValueRules::assertValid($name, $value, in_array(substr($name, 7), $recipe->complexSecretKeys(), true) ? 'complex' : null);
                    }
                    $recipe->template->render($values);
                }
                $this->line('OK '.$recipe->slug.' v'.$recipe->version);
            }

            return 0;
        } catch (InvalidRecipeException|RecipeRenderException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}

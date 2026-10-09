<?php

namespace App\Services\Recipes\Exceptions;

final class RecipeRenderException extends \RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("Recipe rendering failed for field [{$field}]");
    }
}

<?php

namespace App\Services\Recipes\Exceptions;

final class InvalidRecipeException extends \RuntimeException
{
    // Exception::$code is an integer; PHP forbids redeclaring it readonly/string.
    public readonly string $recipeCode;

    public function __construct(public readonly string $slug, string $code, string $detail = '')
    {
        $this->recipeCode = $code;
        parent::__construct("Recipe [{$slug}] is invalid ({$code}): {$detail}");
    }

    public function __get(string $name): mixed
    {
        return $name === 'code' ? $this->recipeCode : null;
    }
}

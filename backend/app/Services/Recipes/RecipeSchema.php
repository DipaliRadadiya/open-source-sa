<?php

namespace App\Services\Recipes;

use App\Rules\ContainerMountPath;
use App\Services\Recipes\Exceptions\InvalidRecipeException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RecipeSchema
{
    public static function rules(): array
    {
        return [
            'schema' => ['required', 'integer:strict', Rule::in([1])],
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/D'],
            'version' => ['required', 'integer:strict', 'min:1'],
            'title' => ['required', 'string', 'min:1', 'max:60'],
            'tagline' => ['required', 'array'],
            'tagline.en' => ['required', 'string'],
            'tagline.*' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'array'],
            'description.en' => ['required_with:description', 'string'],
            'description.*' => ['string', 'max:600'],
            'category' => ['required', Rule::in(explode(',', 'cms,security,development,productivity,monitoring,analytics,communication,database,ecommerce,automation,utility,business,education,marketing,community'))],
            'icon' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,40}$/D'],
            'popular' => ['sometimes', 'boolean:strict'],
            'deprecated' => ['sometimes', 'boolean:strict'],
            'catalog_order' => ['required', 'integer:strict', 'between:1,9999'],
            'docs_url' => ['sometimes', 'string', 'max:200', 'url:https'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['required', 'array:ref,tested'],
            'images.*.ref' => ['required', 'string'],
            'images.*.tested' => ['present', 'array'],
            'images.*.tested.*' => ['string'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['array:name,role'],
            'services.*.name' => ['required', 'distinct', 'regex:/^[a-z][a-z0-9_-]{0,30}$/D'],
            'services.*.role' => ['required', Rule::in(['app', 'database', 'cache', 'worker'])],
            'container_port' => ['required', 'integer:strict', 'between:1,65535'],
            'memory_floor' => ['nullable', 'string', 'regex:/^\d+(b|k|m|g)?$/iD'],
            'volumes' => ['sometimes', 'array'],
            'volumes.*' => ['required', 'regex:'.ContainerMountPath::PATTERN, Rule::notIn(['/'])],
            'secrets' => ['sometimes', 'array'],
            'secrets.*' => ['array:key,complexity,panel_only'],
            'secrets.*.key' => ['required', 'distinct', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/D'],
            'secrets.*.complexity' => ['required', Rule::in(['alnum', 'complex'])],
            'secrets.*.panel_only' => ['required', 'boolean:strict'],
            'url_env_key' => ['nullable', 'regex:/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D'],
            'inputs' => ['sometimes', 'array'],
            'inputs.*' => ['array:name,required'],
            'inputs.*.name' => ['required', 'distinct', Rule::in(['admin_email', 'admin_username'])],
            'inputs.*.required' => ['required', 'boolean:strict'],
            'starter_files' => ['sometimes', 'array'],
            'starter_files.*' => ['array:path,source'],
            'starter_files.*.path' => ['required', 'distinct', 'regex:'.ContainerMountPath::PATTERN],
            'starter_files.*.source' => ['required', 'regex:/^starter\/[A-Za-z0-9._-]+$/D'],
            'first_run' => ['required', 'array:kind,credentials'],
            'first_run.kind' => ['required', Rule::in(['credentials', 'claimed', 'setup_wizard', 'open_registration', 'default_login', 'none'])],
            'first_run.credentials' => ['present', 'nullable', 'array', 'min:1'],
            'first_run.credentials.*' => ['array:label,value,secret,input,path'],
            'first_run.credentials.*.label' => ['required', Rule::in(['username', 'password', 'email', 'url', 'token'])],
            'after_install' => ['nullable', 'array:type,path,fields'],
            'after_install.type' => ['required_with:after_install', Rule::in(['http_form_claim'])],
            'after_install.path' => ['required_with:after_install', 'regex:#^/[A-Za-z0-9._~/\-]*$#D'],
            'after_install.fields' => ['required_with:after_install', 'array', 'min:1'],
            'after_install.fields.*' => ['required', 'string'],
            'hook' => ['nullable', 'string'],
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['string'],
        ];
    }

    public static function check(array $data, string $dir): void
    {
        $slug = basename($dir);
        $fail = fn (string $code) => throw new InvalidRecipeException($slug, $code);
        if (Validator::make($data, self::rules())->fails()) {
            $fail('schema');
        }
        if ($data['slug'] !== $slug) {
            $fail('slug_mismatch');
        }
        foreach (['tagline', 'description'] as $key) {
            if (array_key_exists($key, $data) && ! array_key_exists('en', $data[$key])) {
                $fail('schema');
            }
            if (array_diff(array_keys($data[$key] ?? []), config('recipes.locales'))) {
                $fail('schema');
            }
        }
        foreach ($data['images'] as $role => $image) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $role)) {
                $fail('schema');
            }
            foreach ([$image['ref'], ...$image['tested']] as $ref) {
                if (! RecipeValueRules::imageRefValid($ref)) {
                    $fail('image_untagged');
                }
            }
        }
        foreach (array_keys($data['volumes'] ?? []) as $role) {
            if (! preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $role)) {
                $fail('schema');
            }
        }
        if (count(array_filter($data['services'], fn ($s) => $s['role'] === 'app')) !== 1) {
            $fail('schema');
        }
        if (! empty($data['hook']) && ! empty($data['after_install'])) {
            $fail('schema');
        }
        $keys = array_column($data['secrets'] ?? [], 'key');
        $inputs = array_column($data['inputs'] ?? [], 'name');
        foreach ($data['first_run']['credentials'] ?? [] as $item) {
            $sources = array_intersect(['value', 'secret', 'input', 'path'], array_keys($item));
            if (count($sources) !== 1) {
                $fail('schema');
            }
            $key = reset($sources);
            if (! is_string($item[$key]) || ($key === 'secret' && ! in_array($item[$key], $keys, true)) || ($key === 'input' && ! in_array($item[$key], $inputs, true)) || ($key === 'path' && ! preg_match(ContainerMountPath::PATTERN, $item[$key]))) {
                $fail('schema');
            }
        }
        foreach ($data['starter_files'] ?? [] as $file) {
            if (dirname($file['path']) === '/' || ! is_file($dir.'/'.$file['source']) || is_link($dir.'/'.$file['source'])) {
                $fail('starter_missing');
            }
        }
        foreach ($data['after_install']['fields'] ?? [] as $value) {
            preg_match_all('/\{\{\s*(app\.name|input\.[A-Za-z0-9_]+|secret\.[A-Z0-9_]+)\s*\}\}/', $value, $m);
            if (str_contains(preg_replace('/\{\{\s*(app\.name|input\.[A-Za-z0-9_]+|secret\.[A-Z0-9_]+)\s*\}\}/', '', $value), '{{')) {
                $fail('schema');
            }
            foreach ($m[1] as $name) {
                if ($name !== 'app.name' && ! in_array(substr($name, strpos($name, '.') + 1), str_starts_with($name, 'input.') ? $inputs : $keys, true)) {
                    $fail('schema');
                }
            }
        }
    }
}

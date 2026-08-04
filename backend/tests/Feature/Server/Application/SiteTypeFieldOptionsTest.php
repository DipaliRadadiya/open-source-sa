<?php

use App\Services\Timezones;
use App\Support\FieldOptions;
use Illuminate\Support\Facades\Validator;

/*
 * These fields were free text. The values are not free text — they are codes a
 * specific installer accepts, so a typo produces a site installed in the wrong
 * language, found later by whoever owns it.
 *
 * The failure worth testing is not "are there options" but "do the options and
 * the validation rules agree". A dropdown offering a value the API rejects
 * wastes the user's time and reads as a bug in the panel.
 */

/** @return list<array{type: string, field: string}> the enumerated fields */
function enumeratedFields(): array
{
    return [
        ['type' => 'WordPress', 'field' => 'site_language'],
        ['type' => 'CraftCms', 'field' => 'language'],
        ['type' => 'Akaunting', 'field' => 'locale'],
        ['type' => 'PrestaShop', 'field' => 'country'],
        ['type' => 'PrestaShop', 'field' => 'language'],
        ['type' => 'PrestaShop', 'field' => 'timezone'],
    ];
}

function siteTypeField(string $type, string $field): array
{
    $definition = collect(app("App\\Services\\Applications\\Types\\{$type}SiteType")->fields())
        ->firstWhere('name', $field);

    expect($definition)->not->toBeNull("{$type} has no field {$field}");

    return $definition;
}

it('offers options for every enumerated field, as a select', function () {
    foreach (enumeratedFields() as ['type' => $type, 'field' => $field]) {
        $definition = siteTypeField($type, $field);

        expect($definition['options'] ?? [])->not->toBeEmpty("{$type}.{$field} has no options")
            // A `text` field carrying options would leave the renderer
            // guessing which one to honour.
            ->and($definition['type'])->toBe('select', "{$type}.{$field} is not a select");
    }
});

it('accepts every value it offers', function () {
    // The whole point. If these disagree the user picks from a list and the
    // API refuses the choice.
    foreach (enumeratedFields() as ['type' => $type, 'field' => $field]) {
        $siteType = app("App\\Services\\Applications\\Types\\{$type}SiteType");
        $rules = $siteType->rules();
        $definition = siteTypeField($type, $field);

        foreach ($definition['options'] as $option) {
            $failed = Validator::make(
                [$field => $option['value']],
                [$field => $rules[$field]],
            )->fails();

            expect($failed)->toBeFalse(
                "{$type}.{$field} offers '{$option['value']}' but its own rules reject it",
            );
        }
    }
});

it('defaults to a value that is actually in the list', function () {
    // A default outside the list renders as an empty select, or silently
    // resets the user's choice.
    foreach (enumeratedFields() as ['type' => $type, 'field' => $field]) {
        $definition = siteTypeField($type, $field);
        $values = array_column($definition['options'], 'value');

        expect(in_array($definition['default'], $values, true))->toBeTrue(
            "{$type}.{$field} defaults to '{$definition['default']}', which it does not offer",
        );
    }
});

it('gives every option a value and a label', function () {
    foreach (enumeratedFields() as ['type' => $type, 'field' => $field]) {
        foreach (siteTypeField($type, $field)['options'] as $option) {
            expect($option)->toHaveKeys(['value', 'label'])
                ->and($option['value'])->not->toBeEmpty()
                ->and($option['label'])->not->toBeEmpty();
        }
    }
});

it('has no duplicate options', function () {
    foreach (enumeratedFields() as ['type' => $type, 'field' => $field]) {
        $values = array_column(siteTypeField($type, $field)['options'], 'value');

        expect($values)->toBe(array_values(array_unique($values)), "{$type}.{$field} has duplicates");
    }
});

describe('the option lists themselves', function () {
    it('lists countries as lowercase ISO 3166-1 alpha-2', function () {
        foreach (FieldOptions::countries() as $code) {
            expect($code)->toMatch('/^[a-z]{2}$/');
        }

        // The full standard, not a popular subset — a subset is a decision to
        // exclude somebody.
        expect(FieldOptions::countries())->toHaveCount(249);
    });

    it('lists languages as lowercase ISO 639-1', function () {
        foreach (FieldOptions::languages() as $code) {
            expect($code)->toMatch('/^[a-z]{2}$/');
        }
    });

    it('uses underscores for WordPress and hyphens for the BCP 47 apps', function () {
        // WordPress is not consistent: `ja` and `he_IL` are both correct, so
        // the shape is asserted loosely on purpose.
        foreach (FieldOptions::wordpressLocales() as $locale) {
            expect($locale)->toMatch('/^[a-z]{2,3}(_[A-Z]{2})?$/');
        }

        foreach (FieldOptions::hyphenLocales() as $locale) {
            expect($locale)->toMatch('/^[a-z]{2}(-[A-Z]{2})?$/');
        }
    });

    it('takes timezones from the server rather than a static list', function () {
        // The value has to be one this machine has. A hardcoded list would
        // drift from the OS, which is the authority.
        $offered = array_column(siteTypeField('PrestaShop', 'timezone')['options'], 'value');

        expect($offered)->toBe(app(Timezones::class)->identifiers());
    });
});

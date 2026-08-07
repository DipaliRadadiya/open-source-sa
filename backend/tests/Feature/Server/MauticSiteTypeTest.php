<?php

use App\Services\Applications\Types\MauticSiteType;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;

uses()->group('mautic');

describe('MauticSiteType', function () {
    #[Test]
    public function name_returns_mautic(): void
    {
        $type = new MauticSiteType;
        expect($type->name())->toBe('mautic');
    }

    #[Test]
    public function method_is_one_click(): void
    {
        $type = new MauticSiteType;
        expect($type->method())->toBe('one_click');
    }

    #[Test]
    public function needs_database(): void
    {
        $type = new MauticSiteType;
        expect($type->needsDatabase())->toBeTrue();
    }

    #[Test]
    public function category_is_marketing(): void
    {
        $type = new MauticSiteType;
        expect($type->category())->toBe('marketing');
    }

    describe('fields', function () {
        #[Test]
        public function contains_site_title(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            expect($fields->firstWhere('name', 'site_title'))->not()->toBeNull();
        }

        #[Test]
        public function contains_all_mailer_fields(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $mailerFields = ['mailer_name', 'mailer_email', 'mailer_host', 'mailer_port', 'mailer_username', 'mailer_password'];

            foreach ($mailerFields as $field) {
                expect($fields->firstWhere('name', $field))
                    ->not()->toBeNull("Field [{$field}] should be present in MauticSiteType");
            }
        }

        #[Test]
        public function mailer_name_is_required(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_name');
            expect($field['required'] ?? false)->toBeTrue();
        }

        #[Test]
        public function mailer_host_is_required(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_host');
            expect($field['required'] ?? false)->toBeTrue();
        }

        #[Test]
        public function mailer_port_is_number_type(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_port');
            expect($field['type'])->toBe('number');
        }

        #[Test]
        public function mailer_password_is_masked(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_password');
            expect($field['type'])->toBe('password');
        }

        #[Test]
        public function mailer_email_is_optional(): void
        {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_email');
            expect($field['required'] ?? false)->toBeFalse();
        });
    });

    describe('validation rules', function () {
        #[Test]
        public function site_title_is_required(): void
        {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['site_title'])->toContain('required');
        }

        #[Test]
        public function mailer_port_validates_as_port_range(): void
        {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_port'])->toContain('integer');
            expect($rules['mailer_port'])->toContain('min:1');
            expect($rules['mailer_port'])->toContain('max:65535');
        }

        #[Test]
        public function mailer_email_allows_null(): void
        {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_email'])->toContain('nullable');
        }

        #[Test]
        public function mailer_password_minimum_length(): void
        {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_password'])->toContain('required');
            expect($rules['mailer_password'])->toContain('string');
            expect($rules['mailer_password'])->toContain('max:500');
        }

        #[Test]
        public function admin_password_requires_minimum_length(): void
        {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['admin_password'])->toContain('min:10');
        }
    });
});

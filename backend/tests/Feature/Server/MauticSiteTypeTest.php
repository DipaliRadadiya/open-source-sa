<?php

use App\Services\Applications\Types\MauticSiteType;

uses()->group('mautic');

describe('MauticSiteType', function () {
    it('returns mautic as the site type name', function () {
        $type = new MauticSiteType;
        expect($type->name())->toBe('mautic');
    });

    it('method is one_click', function () {
        $type = new MauticSiteType;
        expect($type->method())->toBe('one_click');
    });

    it('needs a database', function () {
        $type = new MauticSiteType;
        expect($type->needsDatabase())->toBeTrue();
    });

    it('category is marketing', function () {
        $type = new MauticSiteType;
        expect($type->category())->toBe('marketing');
    });

    describe('fields', function () {
        it('contains a site_title field', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            expect($fields->firstWhere('name', 'site_title'))->not()->toBeNull();
        });

        it('contains all required mailer fields', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $mailerFields = ['mailer_name', 'mailer_email', 'mailer_host', 'mailer_port', 'mailer_username', 'mailer_password'];

            foreach ($mailerFields as $field) {
                expect($fields->firstWhere('name', $field))
                    ->not()->toBeNull("Field [{$field}] should be present in MauticSiteType");
            }
        });

        it('mailer_name is required', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_name');
            expect($field['required'] ?? false)->toBeTrue();
        });

        it('mailer_host is required', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_host');
            expect($field['required'] ?? false)->toBeTrue();
        });

        it('mailer_port is a number field', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_port');
            expect($field['type'])->toBe('number');
        });

        it('mailer_password is a password field', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_password');
            expect($field['type'])->toBe('password');
        });

        it('mailer_email is optional', function () {
            $type = new MauticSiteType;
            $fields = collect($type->fields());
            $field = $fields->firstWhere('name', 'mailer_email');
            expect($field['required'] ?? false)->toBeFalse();
        });
    });

    describe('validation rules', function () {
        it('site_title is required', function () {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['site_title'])->toContain('required');
        });

        it('mailer_port validates as a port number', function () {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_port'])->toContain('integer');
            expect($rules['mailer_port'])->toContain('min:1');
            expect($rules['mailer_port'])->toContain('max:65535');
        });

        it('mailer_email allows null', function () {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_email'])->toContain('nullable');
        });

        it('mailer_password has required and max constraints', function () {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['mailer_password'])->toContain('required');
            expect($rules['mailer_password'])->toContain('string');
            expect($rules['mailer_password'])->toContain('max:500');
        });

        it('admin_password requires minimum length of 10', function () {
            $type = new MauticSiteType;
            $rules = $type->rules();
            expect($rules['admin_password'])->toContain('min:10');
        });
    });
});

<?php

namespace App\Services\Applications\Types;

/**
 * Moodle — learning management system.
 */
class MoodleSiteType extends AbstractSiteType
{
    public function name(): string
    {
        return 'moodle';
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'php';
    }

    public function category(): string
    {
        return 'education';
    }

    public function icon(): string
    {
        return 'graduation-cap';
    }

    public function popular(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return array_merge($this->commonFields(), [
            $this->field('site_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.site_name')]),
            $this->field('short_name', 'text', required: true, extra: ['placeholder' => __('application.placeholders.short_name')]),
            $this->field('admin_user', 'text', required: true, extra: ['default' => 'admin']),
            $this->field('admin_email', 'email', required: true, extra: ['placeholder' => __('application.placeholders.admin_email')]),
            $this->field('admin_password', 'password', required: true, extra: ['generate' => true]),
            $this->field('table_prefix', 'text', advanced: true, extra: ['default' => 'mdl_']),
        ], $this->phpFields());
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Moodle's default policy, enforced here rather than waived at
            // install time: waiving it would admit a weak password, and a
            // failed reset would strand the account on a random one.
            'admin_password' => [
                'required', 'string', 'min:8',
                'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
            'table_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * Moodle 5.0.9+, measured 2026-09-18.
     *
     * 🔴 **Pinned, not latest.** `server.installers.moodle.download_url` is
     * `stable500`, so the panel installs the 5.0 line however new Moodle gets.
     * The range has to describe *that*, and a proposal of 8.3 was refused for
     * being Moodle 5.2's floor — correct for a release we do not install.
     *
     * Read from `admin/environment.xml` inside the tarball the panel actually
     * downloads: the `<MOODLE version="5.0">` block declares
     * `<PHP version="8.2.0" level="required">` and no upper bound at all,
     * hence the null ceiling.
     *
     * If `stable500` is ever bumped, this moves with it — which is the whole
     * argument for deriving these from the release rather than typing them.
     */
    public function supportedPhpRange(): ?array
    {
        return ['min' => '8.2', 'max' => null];
    }
}

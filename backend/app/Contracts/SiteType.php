<?php

namespace App\Contracts;

/**
 * One installable thing in the application catalog — WordPress, a git repo, a
 * blank PHP site.
 *
 * A site type describes *itself*, including the fields its create form needs.
 * The frontend renders one generic form from that description, so adding a
 * type is one class here and nothing in the UI.
 *
 * Note what a site type deliberately does NOT decide: the web server. That
 * belongs to the server (only one process can own port 80), and a site type
 * only declares the *serving profile* it needs — the web-server driver knows
 * how to serve each profile.
 */
interface SiteType
{
    /** Stable machine key, e.g. `wordpress`. */
    public function name(): string;

    /** How it is built. Internal — the user is never asked to choose a method. */
    public function method(): string;

    /**
     * The app-sidebar items this type supports, by permission name.
     *
     * The second of the two filters that decide an application's sidebar: the
     * first is what the user has been granted, this is what the site can
     * actually do. A WordPress site has no git repository, so a Deployment
     * screen for it is not a disabled button — it is a screen about nothing.
     *
     * Declared here rather than inferred by the frontend so a new site type
     * costs one class and no frontend change, the same trade the create form
     * already makes. `AbstractSiteType` derives a sensible set from the
     * serving profile and the build method; a type overrides only where it
     * genuinely differs.
     *
     * @return array<int, string>
     */
    public function features(): array;

    /** php | node | static | proxy — what has to be installed to serve it. */
    public function servingProfile(): string;

    /** Grouping for the card grid, e.g. `cms`, `developer`. */
    public function category(): string;

    /** Lucide icon name for the card. */
    public function icon(): string;

    /** Surface it in the "Popular" filter. */
    public function popular(): bool;

    /** Does creating this also need a database? */
    public function needsDatabase(): bool;

    /**
     * The create-form fields, in display order.
     *
     * Each entry: name, label, type, required, plus optionally default,
     * advanced, help, group, options, `source` (which endpoint fills a
     * dropdown) and `depends_on` (don't load until the parent is chosen).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * Extra Laravel validation rules, keyed by field name, merged over the
     * rules derived from fields(). For anything the generic derivation cannot
     * express — e.g. "a git account or a public URL, but not both".
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}

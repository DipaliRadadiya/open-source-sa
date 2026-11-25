<?php

namespace App\Contracts;

use App\Models\Application;
use App\Rules\SupportedPhpVersion;

/**
 * One installable thing in the application catalog — WordPress, a git repo, a
 * blank PHP site.
 *
 * A site type describes *itself*, including the fields its create form needs; the frontend
 * renders one generic form from that, so adding a type is one class and nothing in the UI.
 *
 * A site type does NOT decide the web server (that belongs to the server: only one process can
 * own port 80). It only declares the *serving profile* it needs.
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
     * The second of two sidebar filters: the first is what the user has been granted, this is
     * what the site can actually do (a WordPress site has no git repository, so no Deployment
     * screen). `AbstractSiteType` derives a set from the serving profile and build method; a
     * type overrides only where it differs.
     *
     * @return array<int, string>
     */
    public function features(): array;

    /** php | node | static | proxy — what has to be installed to serve it. */
    public function servingProfile(): string;

    /**
     * Every profile this type can end up served by.
     *
     * `servingProfile()` is only the *default* for some types: a git repository becomes php, node
     * or static depending on the rendering type the user picks (`ServingProfile::resolve()`). A
     * stack that hosts any member of this set can offer the type.
     *
     * @return array<int, string>
     */
    public function servingProfiles(): array;

    /** Grouping for the card grid, e.g. `cms`, `developer`. */
    public function category(): string;

    /** Lucide icon name for the card. */
    public function icon(): string;

    /** Surface it in the "Popular" filter. */
    public function popular(): bool;

    /** Does creating this also need a database? */
    public function needsDatabase(): bool;

    /**
     * Does this application's own process listen on a second loopback port,
     * besides `app_port`? Allocated at create time into `internal_port`
     * (e.g. n8n's task broker).
     */
    public function needsInternalPort(): bool;

    /**
     * Does this application's own browser client authenticate using the HTTP
     * `Authorization` header?
     *
     * A request carries one `Authorization` header and nginx Basic Auth consumes it, so an
     * application sending `Authorization: Bearer <token>` cannot also satisfy Basic Auth (one
     * credential replaces the other and a 401 follows). Types returning true cannot be combined
     * with the panel's password protection.
     */
    public function authorizationHeaderAuth(): bool;

    /**
     * The unit's `MemoryMax` for this application, or null for the server
     * default.
     *
     * A systemd cap is a kill, not a warning: a process over it ends up in `failed` and the
     * site returns 502.
     */
    public function defaultMemoryMax(): ?string;

    /**
     * The Node versions this application will actually run on.
     *
     * `['min' => '22', 'max' => null]` — either end may be null for open.
     * Null means the type has no opinion (every PHP type, and a git site whose
     * code we know nothing about).
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function supportedNodeRange(): ?array;

    /**
     * The npm package this type installs, when it installs one.
     *
     * Lets the Node range be read off the release instead of transcribed and left to age. Null
     * for a type that installs from anywhere else (tarball, git clone) or installs nothing.
     */
    public function npmPackage(): ?string;

    /**
     * The PHP versions this application will actually run on.
     *
     * `['min' => '8.3', 'max' => null]` — either end may be null for open, and null means the
     * type has no opinion (a blank PHP site or git deployment runs whatever the user's code
     * runs, and a range there would refuse a working version).
     *
     * Stated as major.minor, as PHP projects state support and as {@see SupportedPhpVersion} compares it.
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function supportedPhpRange(): ?array;

    /**
     * The range for a site that already exists — which may not be the type's.
     *
     * The same for almost every type, except where the installer picks a release by PHP: a
     * PrestaShop installed as 8.x stops at PHP 8.1 for good. The PHP screen asks this, so an
     * existing site is never moved onto a version its own release cannot run.
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function supportedPhpRangeFor(Application $application): ?array;

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

    /**
     * How to clone/push a staging site of this type, or null when it has no staging recipe
     * (the feature is then not offered, rather than failing at the first click).
     */
    public function stagingStrategy(): ?StagingStrategy;

    /**
     * Only meaningful when `needsDatabase()` is true — null there means
     * Site Clone refuses to clone this type rather than produce a clone
     * whose config still points at the source's database. A type with no
     * database needs no strategy at all; `CloneManager` copies its files
     * generically.
     */
    public function cloneStrategy(): ?CloneStrategy;

    /**
     * URL paths this type's web root holds that must never be served,
     * as PCRE patterns matched against the request path (leading `/`).
     *
     * For an application that ships its protection as Apache `.htaccess` files, which nginx never
     * reads and OpenLiteSpeed reads only where the vhost enables it. Without lookaheads:
     * OpenLiteSpeed's regex contexts are not documented as supporting them.
     *
     * @return array<int, string>
     */
    public function deniedPaths(): array;

    /**
     * Top-level directories that are applications of their own, each with its
     * own front controller — what the application's `.htaccess` in that
     * directory does on Apache. `directory` is a regex for the directory name
     * (no slashes, no lookaheads), `script` the file inside it.
     *
     * @return array<int, array{directory: string, script: string}>
     */
    public function subdirectoryFrontControllers(): array;

    /**
     * `/.well-known/<name>` URIs this application answers: `redirects` maps a
     * name to a path it is sent to (301); `fallback`, when set, is where every
     * other well-known URI goes — the ACME and PKI validation paths excepted.
     * What the application's `.htaccess` does on Apache.
     *
     * @return array{redirects: array<string, string>, fallback: string|null}
     */
    public function wellKnownRoutes(): array;

    /**
     * Paths the application calls on itself over HTTP, which Password
     * Protection must let through when — and only when — the request comes
     * from the server itself. Exact paths, each starting with `/`.
     *
     * @return array<int, string>
     */
    public function serverOnlyPaths(): array;

    /**
     * File extensions the web server must serve with a specific type, which
     * the application sets in its `.htaccess` on Apache.
     *
     * @return array<string, string>
     */
    public function mimeTypes(): array;

    /**
     * `.env` keys the Environment editor may not change or remove once they hold a value: the
     * key the application encrypts its stored data with. A different one makes that data
     * unreadable, with nothing saying so until the application next reads it.
     *
     * @return array<int, string>
     */
    public function lockedEnvironmentKeys(): array;

    /**
     * `.env` keys the panel itself relies on, refused in the Environment editor the same way:
     * the port the vhost proxies to, the folder the service keeps its data in, the address it
     * listens on. A different value fails at the next restart (502, or an application published
     * past its vhost).
     *
     * Kept apart from {@see lockedEnvironmentKeys()} only for the message: the reason differs.
     *
     * @return array<int, string>
     */
    public function panelManagedEnvironmentKeys(): array;
}

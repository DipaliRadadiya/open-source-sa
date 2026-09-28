<?php

namespace App\Services\Applications\Types;

/**
 * A one-click application that runs as containers.
 *
 * The shared half of every Docker app card. A subclass says what the app IS —
 * its compose template, the port to proxy, the volumes it keeps data in, the
 * secrets it needs generating — and nothing about how any of that is applied.
 *
 * **Why these are site types and not a catalogue file.** They sit beside the
 * sixteen host-installed one-click types and are read by the same catalog, the
 * same stack gate and the same create form. A YAML list would be quicker to
 * extend and would need its own loader, its own validation and its own answer
 * to every question `AbstractSiteType` already answers — and the first time one
 * app needed a rule the others did not, it would grow a scripting language.
 *
 * **Why `method() === 'one_click'` and `servingProfile() === 'docker'`.** The
 * profile is what the stack gate reads, so these appear on a Docker box and are
 * filtered off a LEMP one with no extra code — the mirror of how the PHP
 * one-clicks are filtered off a Docker box. The method drives which screens the
 * site gets.
 *
 * **No fields.** `DockerSiteType` asks for an image and a port because the user
 * is choosing what to run. Here the app has already answered both, and a field
 * offering to change them would be offering to break the compose file the panel
 * is about to write. The domain and the system user are all these need.
 */
abstract class AbstractDockerAppType extends AbstractSiteType
{
    /**
     * The blade template that renders this app's compose file.
     *
     * A template per app rather than one template with conditionals: these
     * files differ in service count, in environment, and in which volume holds
     * what, and a single view covering all of them would be unreadable long
     * before the third app.
     */
    abstract public function composeTemplate(): string;

    /**
     * The port INSIDE the app's own container, which nginx is proxied to.
     *
     * Declared even though `ComposeValidator::publishedPort()` can find it in
     * the rendered file, because the template needs it to write the port
     * mapping in the first place — and the two must be the same number.
     */
    abstract public function containerPort(): int;

    /**
     * The volumes this app keeps data in, as role => path inside the container.
     *
     * Roles, not names: the name is `sv-app-<id>_<role>`, decided at install
     * time because it needs the id. Roles are what the template refers to, so
     * a template never contains a site-specific string.
     *
     * @return array<string, string>
     */
    abstract public function volumeRoles(): array;

    /**
     * Environment keys that must be generated per site, never defaulted.
     *
     * A shipped default secret in a self-hosted panel is a vulnerability, not a
     * convenience: every install of that app on every server would share it.
     * The installer generates one value per key per site.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return [];
    }

    /**
     * The same two screens a plain container site does not get, and for the same
     * reasons: Clone copies served files, and a container's state is in its
     * volumes; Backup archives the document root and a database, and a container
     * has neither — the document root holds a compose file.
     *
     * Inherited from the reasoning in {@see DockerSiteType}, not duplicated: if
     * volume backup lands, both want it at once.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return array_values(array_diff(parent::features(), ['app_clone', 'app_backup']));
    }

    public function method(): string
    {
        return 'one_click';
    }

    public function servingProfile(): string
    {
        return 'docker';
    }

    /**
     * A container brings its own database, which is the premise of the stack —
     * it is why a Docker server manages no engine.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * Nothing to ask. The app decides its image, its port and its volumes; the
     * create form needs the domain and the system user, which every type gets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

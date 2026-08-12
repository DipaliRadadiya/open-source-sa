<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'A sync is already running. Wait for it to finish before starting another.',
    ],

    'reasons' => [
        'firewall_direction_unsupported' => 'This is an outbound rule. The panel only manages inbound rules, and recording it here would apply it in the wrong direction.',
        'firewall_action_unsupported' => 'This rule limits or rejects rather than allowing or denying. The panel has no equivalent, and recording it as a plain allow or deny would misstate what the server does.',
        'firewall_app_profile' => 'This rule uses an application profile rather than a port. The ports behind it can change when the package updates, so importing today\'s numbers would be a snapshot pretending to be the rule.',
        'panel_infrastructure' => 'This is the panel itself, not a site it can host. Left alone deliberately.',
        'outside_panel_layout' => 'This site is not laid out the way the panel manages sites, so it cannot be adopted without moving its files. It is still being served — nothing has changed.',
        'vhost_unreadable' => 'The web server config for this site could not be read, so it was left alone.',
        'vhost_unparsed' => 'This site is being served, but its config is not in a shape the panel could read. Adopt it by hand, or check the file.',
        'owner_not_tracked' => 'The Linux account that owns this site is not one the panel manages. Sync system users first, then run this again.',
        'unreadable_key' => 'This line is not a public key the panel can read, so it was left alone. It may still grant access — check it by hand.',
        'discovery_failed' => 'This could not be read from the server. Nothing was changed.',
        'adopt_failed' => 'Found on the server, but the panel could not create a record for it.',
        'requires_system_user' => 'Skipped because system users were not part of this run, and this needs them first.',
    ],

];

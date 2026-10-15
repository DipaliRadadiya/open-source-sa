<?php

use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\RecipeValueRules;

it('accepts strictly typed values', function ($name, $value, $complexity = null) {
    RecipeValueRules::assertValid($name, $value, $complexity);
    expect(true)->toBeTrue();
})->with([
    ['project', 'sv-app-123'], ['app_port', 1], ['container_port', 65535], ['memory_limit', '512m'], ['db_memory_limit', '2G'], ['cpu_limit', null], ['cpu_limit', '1.5'], ['url', 'https://x.test:443'], ['domain', 'x.test'], ['site_root', '/home/u/site'], ['secret.A', 'abcdefgh'], ['secret.A', 'Aa1!#xyz', 'complex'], ['volume.data', 'sv-app-1_data'], ['input.admin_email', 'admin@x.test'], ['input.admin_username', 'admin'],
]);
it('refuses invalid values', function ($name, $value) {
    expect(fn () => RecipeValueRules::assertValid($name, $value))->toThrow(RecipeRenderException::class);
})->with([
    ['domain', "x.test\nprivileged: true"], ['memory_limit', "512m\n"], ['cpu_limit', '1.5"'], ['domain', '$HOME'], ['domain', 'a b'], ['domain', '*alias'], ['url', 'https://x.test/path'], ['image.app', 'ghost'], ['image.app', 'Ghost:5'], ['project', 'sv-app-1\r'], ['app_port', '80'], ['app_port', 0], ['container_port', 65536], ['secret.A', 'short'], ['input.admin_username', 'Admin'], ['site_root', null], ['cpu_limit', ''], ['domain', null],
]);
it('accepts every legacy default image', function ($ref) {
    expect(RecipeValueRules::imageRefValid($ref))->toBeTrue();
})->with([
    'matomo:5-apache', 'mariadb:10.11', 'grafana/grafana:13.2', 'lscr.io/linuxserver/bookstack:latest', 'mariadb:11.4', 'wordpress:7.1-apache', 'mattermost/mattermost-team-edition:release-10', 'postgres:16-alpine', 'chatwoot/chatwoot:v4.17.1', 'pgvector/pgvector:pg16', 'redis:7-alpine', 'excalidraw/excalidraw:latest', 'metabase/metabase:latest', 'nocodb/nocodb:latest', 'ghcr.io/requarks/wiki:2', 'codeberg.org/forgejo/forgejo:9', 'freshrss/freshrss:latest', 'gitea/gitea:1', 'glanceapp/glance:latest', 'ghcr.io/gethomepage/homepage:latest', 'corentinth/it-tools:latest', 'stirlingtools/stirling-pdf:latest', 'vaultwarden/server:latest', 'ghost:5-alpine', 'mysql:8.0',
]);

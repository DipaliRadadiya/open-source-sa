import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("a view-only user sees Clear disabled with the reason, not hidden", () => {
  const panel = read("components/logs/logs-panel.jsx");
  assert.match(panel, /onClear=\{source \? \(\) => setConfirmClear\(true\) : null\}/);
  assert.match(panel, /clearReason=\{!source\?\.clearable \? t\("notClearable"\) : canManage \? null : t\("noPermission"\)\}/);
  const bar = read("components/logs/log-toolbar.jsx");
  assert.match(bar, /disabled=\{disabled \|\| clearing \|\| Boolean\(clearReason\)\}/);
  assert.match(bar, /disabledReason=\{clearReason\}/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) assert.ok(JSON.parse(read(`messages/${l}.json`)).logs.noPermission, l);
});

test("Clear log is red: the destructive variant, not outline overridden by hand", () => {
  const bar = read("components/logs/log-toolbar.jsx");
  const clear = bar.slice(bar.indexOf('<div className="ml-auto">'), bar.indexOf('{t("clear")}'));
  assert.match(clear, /variant="destructive"/);
  assert.doesNotMatch(clear, /variant="outline"/);
});

test("axe fixes on System Logs: gutter, selected source, disabled download name", () => {
  assert.doesNotMatch(read("components/logs/log-line.jsx"), /text-console-muted\/60/);
  assert.match(read("components/logs/log-source-list.jsx"), /bg-primary\/10 font-medium text-\[color-mix\(in_oklch,var\(--primary\)_80%,var\(--foreground\)\)\]/);
  assert.match(read("components/logs/log-toolbar.jsx"), /asChild=\{!disabled\} aria-label=\{disabled \? label : undefined\}/);
});

test("the log header wraps the tail pill instead of squeezing the heading", () => {
  assert.match(read("components/logs/log-toolbar.jsx"), /<div className="flex flex-wrap items-center gap-x-3 gap-y-2">\s*<div className="min-w-48 flex-1">/);
});

test("non-file logs show Download and Clear disabled with the reason; big logs explain why Live is off (Krishna 2 Oct)", () => {
  const panel = read("components/logs/logs-panel.jsx");
  assert.match(panel, /downloadReason=\{source\?\.downloadable === false \? t\("notDownloadable"\) : null\}/);
  assert.match(panel, /!follow && followPref !== "off" && appends && source\?\.readable && \(source\?\.size \?\? 0\) > AUTO_FOLLOW_MAX_BYTES/);
  const bar = read("components/logs/log-toolbar.jsx");
  assert.match(bar, /disabled=\{disabled \|\| Boolean\(downloadReason\)\}/);
  assert.match(bar, /t\("serverTime"\),\s*followHint,/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${l}.json`)).logs;
    for (const k of ["liveOffLarge", "notDownloadable", "notClearable"]) assert.ok(m[k], `${l} ${k}`);
  }
});

test("server settings: hostname rule mirrors the backend; saves say what failed", () => {
  const schema = read("lib/schemas/settings.js");
  assert.match(schema, /\.max\(64, "hostnameTooLong"\)/);
  assert.match(schema, /\(\\\.\[a-zA-Z0-9\]\(\[a-zA-Z0-9-\]\{0,61\}\[a-zA-Z0-9\]\)\?\)\*\$\//);
  assert.match(read("lib/settings/validation-message.js"), /"hostnameTooLong"/);
  for (const [f, key] of [["general-form", "saveFailed"], ["swap-form", "swap.saveFailed"], ["redis-form", "redis.saveFailed"], ["ssh-form", "saveFailed"]]) {
    assert.match(read(`components/settings/${f}.jsx`), new RegExp(`fallback: t\\("${key.replace(".", "\\.")}"\\)`), f);
  }
  const m = read("components/settings/maintenance-card.jsx");
  assert.match(m, /fallback: t\("updates\.saveFailed"\)/);
  assert.match(m, /fallback: t\("schedule\.saveFailed"\)/);
});

test("SSH port move: the backend closes the old port's rule itself, so the form does not try again", () => {
  const form = read("components/settings/ssh-form.jsx");
  const page = read("app/(app)/settings/security/page.jsx");
  assert.doesNotMatch(form, /updateFirewallRule|closeOldPort|oldPortRule/, "a second close step 404s on the rule the backend already deleted");
  assert.doesNotMatch(page, /getFirewall/);
  const en = JSON.parse(read("messages/en.json")).settings.security.confirm;
  assert.match(en.port, /closes \{from\}/);
  assert.match(en.port, /unless you added the rule/, "a rule the user added stays open (backend keeps origin=user)");
});

test("Redis password row: a withheld value is not reported as 'no password'", () => {
  const form = read("components/settings/redis-form.jsx");
  assert.match(form, /redis\?\.has_password\s*\?\s*canManage\s*\?\s*t\("redis\.passwordSet"\)\s*:\s*null\s*:\s*t\("redis\.passwordHintNone"\)/);
  assert.match(form, /!canManage \? \(\s*<p className="text-sm">\s*\{redis\?\.has_password\s*\? t\("redis\.passwordSet"\)\s*: t\("redis\.passwordNone"\)\}/);
});

test("restart delay strings agree with their number in every locale", () => {
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const r = JSON.parse(read(`messages/${l}.json`)).settings.maintenance.reboot;
    for (const k of ["inMinutes", "confirmDelayed", "scheduled"]) assert.match(r[k], /^\{minutes, plural,/, `${l}.${k}`);
  }
});

test("swap usage line: bytes formatted for the locale, and free to wrap", () => {
  const form = read("components/settings/swap-form.jsx");
  assert.match(form, /size: formatBytes\(swap\.size, format\) \?\? swap\.size_human/);
  assert.match(form, /used: formatBytes\(swap\.used, format\) \?\? swap\.used_human/);
  assert.doesNotMatch(form, /<p className="text-sm whitespace-nowrap">\s*\{swap\?\.enabled/, "Russian ran 24px past the card at 1280");
});

test("sync: a run that died is not reported as 'nothing found'", () => {
  const summary = read("components/sync/sync-summary.jsx");
  assert.match(summary, /const stopped = run\.finished && run\.status === "failed";/);
  assert.match(summary, /const failed = stopped \|\| totals\.failed > 0;/);
  assert.match(read("components/sync/sync-panel.jsx"), /run\.status === "failed" \? null : \(\s*<EmptyState/);
});

test("sync adopt: the count leaves out types whose parent is unticked, and offers only types with something to add", () => {
  const dialog = read("components/sync/adopt-dialog.jsx");
  assert.match(dialog, /selectedTypes: adopting\.filter\(\(type\) => !blocked\.has\(type\)\)/);
  assert.match(dialog, /item\.resource_type === type && item\.action === "found"/);
  assert.doesNotMatch(dialog, /includeFirewallHint/, "the hint described opening an application's ports");
});

test("sync calls them Applications, as the rest of the panel does", () => {
  assert.equal(JSON.parse(read("messages/en.json")).sync.types.application, "Applications");
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const s = JSON.parse(read(`messages/${l}.json`)).sync;
    for (const k of ["stoppedScan", "stoppedScanHint", "stoppedAdopt", "stoppedAdoptHint"]) assert.ok(s.summary[k], `${l}.${k}`);
  }
});

test("sync: an adopt in progress counts what it has handled, not what it 'found'", () => {
  assert.match(read("components/sync/sync-summary.jsx"), /preview\s*\? t\("summary\.foundSoFar", \{ count: loaded \}\)\s*: t\("summary\.handledSoFar", \{ count: loaded \}\)/);
});

test("sync adopt: PHP settings, workers and certificates still go with the applications", () => {
  const sel = read("lib/server/sync-selection.js");
  assert.match(sel, /export function deferredTypes\(items\)/);
  assert.match(sel, /item\.resource_key === item\.resource_type &&\s*DEPENDS_ON\[item\.resource_type\] === "application"/);
  const dialog = read("components/sync/adopt-dialog.jsx");
  assert.match(dialog, /deferred\.includes\(type\) \|\|/, "an apply reads these only after adopting the sites; leaving them out of `only` means they are never read");
  assert.match(dialog, /t\("adopt\.afterApplications"\)/);
});

test("sync evidence: a list prints as a list, not as index = value", () => {
  assert.match(read("components/sync/sync-evidence.jsx"), /if \(Array\.isArray\(value\)\) \{\s*return <span className="font-mono text-xs break-all">\{value\.map\(String\)\.join\(", "\)\}<\/span>;/);
});

test("sync adopt: a parent with nothing to add is still sent, or a site of a user the panel already has is skipped", () => {
  const sel = read("lib/server/sync-selection.js");
  assert.match(sel, /export function withImplicitParents\(types, offered\)/);
  assert.match(sel, /if \(parent && !sent\.includes\(parent\) && !offered\.includes\(parent\)\) sent\.push\(parent\);/);
  assert.match(read("components/sync/adopt-dialog.jsx"), /withImplicitParents\(\s*includeFirewall \? \[\.\.\.selected, FIREWALL_RESOURCE_TYPE\] : selected,\s*adoptable,\s*\)/);
});

test("sync: an add that fails to start says so (not 'the scan'), and counts are number-formatted", () => {
  assert.match(read("components/sync/sync-panel.jsx"), /mode === "apply" \? t\("errors\.addFailed"\) : t\("errors\.startFailed"\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const s = JSON.parse(read(`messages/${l}.json`)).sync;
    assert.ok(s.errors.addFailed, l);
    assert.match(s.results.showing, /\{shown, number\}/, l);
    assert.match(s.results.showing, /\{total, number\}/, l);
  }
});

test("sync: every backend discoverer is known to the frontend, in its order, with a label in every locale", () => {
  const provider = read("../backend/app/Providers/AppServiceProvider.php");
  const classes = [...provider.matchAll(/\$app->make\((\w+Discoverer)::class\)/g)].map((m) => m[1]);
  const backend = classes.map((c) => read(`../backend/app/Services/Server/Sync/Discoverers/${c}.php`).match(/function resourceType\(\): string\s*\{\s*return '([a-z0-9_]+)';/)[1]);
  const frontend = [...read("lib/schemas/sync.js").match(/SYNC_RESOURCE_TYPES = \[([\s\S]*?)\]/)[1].matchAll(/"([a-z0-9_]+)"/g)].map((m) => m[1]);
  assert.deepEqual(frontend, backend);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const types = JSON.parse(read(`messages/${l}.json`)).sync.types;
    for (const type of backend) assert.ok(types[type], `${l}: sync.types.${type}`);
  }
});

test("bug #29: an extension without our own description shows apt's summary, and is searchable by it", () => {
  assert.match(read("lib/schemas/php.js"), /summary: z\.string\(\)\.nullish\(\)/);
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /t\.has\(`extensionInfo\.\$\{extension\.name\}`\) \? t\(`extensionInfo\.\$\{extension\.name\}`\) : \(extension\.summary \?\? null\)/);
  assert.match(card, /return Boolean\(describe\(extension\)\?\.toLowerCase\(\)\.includes\(term\)\);/);
});

test("bug #67: a secret in the .env history reads 'Changed (hidden)', not two empty sides", () => {
  const diff = read("components/applications/environment/environment-diff.jsx");
  assert.equal((diff.match(/change\.secret \?/g) ?? []).length, 2, "table and stacked rows");
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const h = JSON.parse(read(`messages/${l}.json`)).applications.environment.history.hidden;
    for (const s of ["added", "changed", "removed"]) assert.ok(h[s], `${l}.hidden.${s}`);
  }
  assert.equal(JSON.parse(read("messages/en.json")).applications.environment.history.hidden.changed, "Changed (hidden)");
});

test("bug #82: the exceptions hint says path only and at least 4 characters", () => {
  const hint = JSON.parse(read("messages/en.json")).applications.firewall.exceptionsHint;
  assert.match(hint, /web address path/);
  assert.match(hint, /at least 4 characters/);
  assert.doesNotMatch(hint, /browser name\. Keep|matched against the web address, the part after/);
});

test("a disabled Select says why, like Button and Input", () => {
  const select = read("components/ui/select.jsx");
  assert.match(select, /SelectDisabledContext\.Provider value=\{Boolean\(props\.disabled\)\}/);
  assert.match(select, /reason=\{disabled \? \(disabledReason \?\? inheritedReason\?\.reason\) : null\}/);
  assert.match(select, /if \(disabled && inheritedReason\?\.handled && !disabledReason\) return control;/);
});

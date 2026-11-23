import assert from "node:assert/strict";
import fs from "node:fs";
import { execSync } from "node:child_process";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");

// 23 Nov (Krishna): an action the role has no permission for is hidden. No greyed button with
// a "you don't have permission" tooltip, no view-only badge or note. Disabled-with-reason is
// kept for actions the user may do but cannot right now (a backup already running).
test("DENIED hides buttons, tooltips' children, menu items and save bars", () => {
  const tooltip = read("components/ui/reason-tooltip.jsx");
  assert.match(tooltip, /export const DENIED = "__denied__";/);
  assert.match(tooltip, /if \(reason === DENIED\) return null;/);
  assert.match(read("components/ui/button.jsx"), /if \(denied \|\| disabledReason === DENIED \|\| \(disabled && inheritedReason\?\.denied\)\) return null;/);
  assert.match(read("components/data-table/menu-item-hint.jsx"), /if \(hint === DENIED\) return null;/);
  assert.match(read("components/ui/card-save-footer.jsx"), /if \(saveReason === DENIED\) return null;/);
});

test("switches, inputs and selects stay on screen to show their value, without a tooltip", () => {
  assert.match(read("components/ui/reason-tooltip.jsx"), /return reason === DENIED \? null : reason;/);
  for (const f of ["switch", "input", "select"]) {
    assert.match(read(`components/ui/${f}.jsx`), /readOnlyReason\(disabledReason \?\? inheritedReason\?\.reason\)/, f);
  }
  // Switches wrapped in their own ReasonTooltip strip DENIED the same way.
  assert.match(read("components/firewall/rules-card.jsx"), /<ReasonTooltip reason=\{readOnlyReason\(guarded\)\}>/);
});

test("no screen shows a 'no permission' sentence any more", () => {
  // The create page itself is a full-page refusal for a URL typed by hand, so it keeps its line.
  const hits = execSync(
    `grep -rn --include=*.jsx --include=*.js -E 't[A-Za-z]*\\("([a-z]+\\.)?noPermission"\\)' app components || true`,
    { encoding: "utf8" },
  )
    .split("\n")
    .filter(Boolean)
    .filter((line) => !line.startsWith("app/(app)/applications/create/page.jsx"));
  assert.deepEqual(hits, []);
});

test("menus and rows a role cannot use go away whole", () => {
  assert.match(read("components/firewall/rule-parts.jsx"), /export function RuleRowMenu\([^)]*\) \{\s*if \(!canManage\) return null;/);
  assert.match(read("components/applications/workers/worker-actions.jsx"), /if \(!canManage\) return null;/);
  assert.match(read("components/databases/delete-database-card.jsx"), /if \(!canManage\) return null;/);
  assert.match(read("components/fail2ban/recommended-setup.jsx"), /if \(!canManage\) return null;/);
  assert.match(read("components/docker/docker-resources-panel.jsx"), /if \(attachReason === DENIED && removeReason === DENIED\) return null;/);
  assert.match(read("components/applications/files/files-table.jsx"), /columns=\{canManage \? columns : columns\.filter\(\(column\) => column\.id !== "select"\)\}/);
  assert.match(read("components/applications/files/files-panel.jsx"), /\{siteSearch \|\| !canManage \? null : \(\s*<SelectionBar/);
  // Without start/stop rights only the read-only items (logs, config test) are left.
  assert.match(read("components/services/service-actions.jsx"), /const actions = canManage\s*\?/);
  // A reason shown as text must never print the sentinel.
  assert.match(read("components/backups/backups-cards.jsx"), /blocker && blocker !== DENIED && backup\.status !== "failed"/);
});

test("view-only settings, Disk Cleaner and Bot Blocker show values with no save, picker or add field", () => {
  const row = read("components/settings/setting-row.jsx");
  assert.doesNotMatch(row, /t\("readOnly"\)/);
  assert.match(row, /const showActions = Boolean\(actions\) && !readOnly && !\(actions\.type === SectionActions && actions\.props\.canManage === false\);/);
  assert.match(row, /if \(!canManage\) return null;/);
  assert.doesNotMatch(read("components/settings/maintenance-card.jsx"), /readOnly"\)/);
  assert.doesNotMatch(read("components/applications/environment/environment-editor.jsx"), /t\("readOnly"\)/);
  const cleaner = read("components/disk-cleaner/cleanup-panel.jsx");
  assert.match(cleaner, /\{!nothingToClean && canManage \? \(/);
  assert.doesNotMatch(cleaner, /disabled=\{!canManage\}/);
  const bots = read("components/applications/bot-blocker/bot-blocker-section.jsx");
  assert.equal((bots.match(/readOnly=\{!canManage\}/g) ?? []).length, 2);
  assert.match(bots, /\{readOnly \? null : \(\s*<div className="flex gap-2 pt-1">/);
});

// 23 Nov (Krishna): a greyed switch read as broken. Without the permission the state shows as
// an On/Off pill, or nothing where a badge beside the title already says it.
test("a read-only switch shows On/Off instead of a locked switch", () => {
  const sw = read("components/ui/switch.jsx");
  assert.match(sw, /if \(readOnly \|\| \(disabled && \(disabledReason === DENIED \|\| inheritedReason\?\.denied \|\| inheritedReason\?\.reason === DENIED\)\)\) \{/);
  assert.match(sw, /variant=\{checked \? "success" : "muted"\}/);
  assert.match(read("components/ui/pending-switch.jsx"), /if \(readOnly\) return <Switch \{\.\.\.props\} readOnly \/>;/);
  for (const [f, flag] of [
    ["components/firewall/rules-card.jsx", "canManage"],
    ["components/firewall/rules-cards.jsx", "canManage"],
    ["components/services/service-boot-switch.jsx", "canToggle"],
    ["components/cron-jobs/cronjob-active-switch.jsx", "canManage"],
    ["components/system-users/access-switch.jsx", "canManage"],
    ["components/dashboard/firewall-quick-switch.jsx", "canManage"],
  ]) assert.match(read(f), new RegExp(`readOnly=\\{!${flag}\\}`), f);
  for (const l of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    const c = JSON.parse(read(`messages/${l}.json`)).common;
    assert.ok(c.on && c.off, l);
  }
});

test("where the title badge already says the state, the read-only switch is hidden", () => {
  assert.match(read("components/applications/firewall/firewall-section.jsx"), /\{canManage \? \(\s*<div className="flex h-5 shrink-0 items-center">\s*<Switch/);
  assert.match(read("components/applications/security/security-section.jsx"), /\{canManage \? \(\s*<div className="flex h-5 shrink-0 items-center">\s*<FormControl>/);
  assert.match(read("components/php/extensions-card.jsx"), /\{canManage \? \(\s*<ReasonTooltip reason=\{reason\}>\s*<Switch/);
  assert.match(read("components/fail2ban/jails-card.jsx"), /\{canManage \? \(\s*<div className="col-start-2 row-start-1 flex justify-end">\s*<PendingSwitch/);
});

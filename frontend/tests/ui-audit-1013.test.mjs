import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna 13 Oct, "do all" on the 41-page audit (reports/ui-audit-1013.md).
const read = (p) => fs.readFileSync(p, "utf8");

test("backup History and Restores are tables from 900px, like Overview", () => {
  for (const f of ["components/backups/backups-history.jsx", "components/backups/restores-list.jsx"]) {
    const s = read(f);
    assert.match(s, /<ListCard from="c900"/);
    assert.match(s, /hidden @min-\[900px\]:block/);
  }
});

test("passed health checks are one compact list", () => {
  assert.match(read("app/admin/doctor/page.jsx"), /<PassedChecks checks=\{passed\}/);
  assert.doesNotMatch(read("app/admin/doctor/page.jsx"), /passed\.map\(\(check\) => \(\s*<CheckRow/);
});

test("services: plain Restart, two summary tiles per phone row", () => {
  assert.match(read("components/services/services-panel.jsx"), /grid grid-cols-2 gap-3 sm:gap-4 @min-\[52rem\]\/tiles:grid-cols-4/);
});

test("log toolbar controls wrap one by one", () => {
  assert.match(read("components/logs/log-toolbar.jsx"), /<div className="contents">\s*\{\/\* Severity/);
});

test("database processes: no second 'nothing running', empty state centred", () => {
  const s = read("components/databases/process-list.jsx");
  assert.match(s, /running\.length > 0 \|\| idle\.length > 0 \?/);
  assert.match(s, /fill && "flex flex-1 flex-col justify-center"/);
});

test("database card badge says what never happened", () => {
  assert.match(read("components/databases/databases-cards.jsx"), /t\("columns\.neverExportedBadge"\)/);
  assert.equal(JSON.parse(read("messages/en.json")).databases.columns.neverExportedBadge, "Never exported");
});

test("live charts wait behind a chart shape", () => {
  assert.match(read("components/dashboard/live-chart-card.jsx"), /<svg\s+aria-hidden\s+viewBox="0 0 400 120"/);
});

test("clone domain starts filled with the suggestion", () => {
  const s = read("components/applications/clone/clone-panel.jsx");
  assert.match(s, /defaultValues: \{ name: "", domain: canManage \? suggestion : "" \}/);
  assert.match(s, /form\.reset\(\{ name: "", domain: canManage \? suggestion : "" \}\)/);
});

test("admin attention titles keep one line from md", () => {
  assert.match(read("components/admin/dashboard/attention-list.jsx"), /md:shrink-0 md:whitespace-nowrap/);
});

test("14 Oct: Git onboarding names the real button; the Files second divider never strands", () => {
  const en = JSON.parse(read("messages/en.json"));
  assert.doesNotMatch(en.git.empty.step2, /form above/);
  assert.match(en.git.empty.step2, /Connect account/);
  assert.match(read("components/applications/files/files-panel.jsx"), /<Separator orientation="vertical" className="mx-0\.5 !h-5 !self-center hidden @\[52rem\]\/toolbar:block" \/>\s*<FixPermissionsButton/);
});

test("14 Oct: banned addresses say how long ago, without trusting the browser's clock zone", async () => {
  const { banAgeSeconds } = await import("../lib/fail2ban/ban-age.js");
  assert.equal(banAgeSeconds({ banned_at: "2026-10-14 03:50:55", expires_at: "2026-10-14 04:50:55", seconds_left: 180 }), 3420);
  assert.equal(banAgeSeconds({ banned_at: "2026-10-14 03:50:55", expires_at: null, seconds_left: null }), null);
  assert.match(read("components/fail2ban/banned-card.jsx"), /<BanAge ban=\{row\.original\} \/>/);
  assert.match(read("components/fail2ban/banned-cards.jsx"), /<BanAge ban=\{ban\} \/>/);
});

test("14 Oct: empty Workers shows no bare toolbar and no template that repeats Add worker", () => {
  const s = read("components/applications/workers/workers-panel.jsx");
  assert.match(s, /workers\.length === 0 \? \([\s\S]{0,160}<ListCard>/);
  assert.match(s, /presets\.filter\(\(p\) => !\(p\.kind === "custom" && !p\.command\)\)/);
  assert.match(s, /starters\.map\(\(p\) =>/);
});

test("14 Oct: every permission in the catalog has a description on the role form", () => {
  const d = JSON.parse(read("messages/en.json")).roles.form.permDesc;
  for (const key of ["app_dashboard", "app_container", "app_compose", "docker", "registry", "app_magic_login"]) assert.ok(d[key], key);
});

test("14 Oct: Workers mentions queue workers and Horizon only where the API offers them", () => {
  assert.match(read("app/(app)/applications/[application]/workers/page.jsx"), /t\(laravelKinds \? "pageSubtitle" : "pageSubtitleGeneric"\)/);
  assert.match(read("components/applications/workers/workers-panel.jsx"), /\? "empty\.description"\s*: "empty\.descriptionGeneric"/);
  const w = JSON.parse(read("messages/en.json")).applications.workers;
  assert.doesNotMatch(w.pageSubtitleGeneric + w.empty.descriptionGeneric, /Horizon|queue/i);
});

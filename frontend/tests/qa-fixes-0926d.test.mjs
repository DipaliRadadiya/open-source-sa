import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const panel = read("components/applications/fail2ban/fail2ban-panel.jsx");

test("FB-A: leaving with edited files asks first", () => {
  assert.match(panel, /useWatchUnsaved\("app-fail2ban", canManage && changed\)/);
});

test("FB-C: view-only on a site that isn't set up says why there is no button", () => {
  assert.match(panel, /: \(\s*<p className="mx-auto max-w-md text-xs text-muted-foreground">\{t\("noPermission"\)\}<\/p>/);
});

test("FB-D: a create or remove holds until the refreshed props agree", () => {
  assert.match(panel, /const config = override === undefined \? serverConfig : override;/);
  assert.match(panel, /setOverride\(\{ jail_name: null, jail_content: draft\.jail, filter_content: draft\.filter \}\)/);
  assert.match(panel, /setOverride\(null\);/);
});

test("FB-E: the status text wraps the button below it instead of shrinking", () => {
  assert.match(panel, /<div className="min-w-48 flex-1 space-y-1">/);
});

test("FB-F: fail2ban's refusal leads with its ERROR lines, full output one click away", () => {
  assert.match(panel, /filter\(\(line\) => \/\\bERROR\\b\/\.test\(line\)\)/);
  for (const l of LOCALES) {
    const f = JSON.parse(read(`messages/${l}.json`)).applications.fail2ban;
    assert.ok(f.outputShowAll && f.outputErrorsOnly, l);
  }
});

test("FB-G: removing what is already removed is not a failure", () => {
  assert.match(panel, /if \(error\.response\?\.status === 422\) removed\(\);/);
});

test("FB-H: a failed read carries its reason to the error box", () => {
  const src = read("lib/applications/get-applications.js");
  const fn = src.slice(src.indexOf("export async function getApplicationFail2ban"), src.indexOf("export async function getApplicationPhp"));
  assert.match(fn, /failure: result\.failure,\s*message: result\.message,\s*debug: result\.debug,/);
});

test("FB-J: Remove can't start while a save is running", () => {
  assert.match(panel, /disabled=\{removing \|\| saving\}/);
});

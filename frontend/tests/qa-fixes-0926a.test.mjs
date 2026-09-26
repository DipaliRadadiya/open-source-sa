import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { parseDismissedRestores, DISMISSED_RESTORES_COOKIE } from "../lib/backups/dismissed-restores.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];

test("BK-C: a finished restore keeps its banner (and Undo) across reloads until dismissed", () => {
  assert.deepEqual(parseDismissedRestores("3,x,17,,0,-2"), [3, 17]);
  assert.deepEqual(parseDismissedRestores(undefined), []);
  const fetcher = read("lib/backups/get-backups.js");
  assert.match(fetcher, /FINISHED_RESTORE_WINDOW_MS = 24 \* 60 \* 60 \* 1000/);
  assert.match(fetcher, /!dismissed\.includes\(latest\.id\)/);
  // A pruned safety copy must not be offered as an undo.
  assert.match(fetcher, /if \(!safety\.data\?\.backup\) safetyBackupId = null;/);
  const page = read("app/(app)/applications/[application]/backups/page.jsx");
  assert.match(page, /parseDismissedRestores\(\(await cookies\(\)\)\.get\(DISMISSED_RESTORES_COOKIE\)\?\.value\)/);
  assert.equal(DISMISSED_RESTORES_COOKIE, "sv_dismissed_restores");
  // Only a finished restore is remembered as dismissed; hiding a running one is not.
  assert.match(read("components/backups/active-restore.jsx"), /if \(!RESTORE_IN_FLIGHT\.includes\(latest\.status\)\) rememberDismissedRestore\(latest\.id\);/);
  // The callback is read through a ref, so an inline function cannot restart the polling.
  const progress = read("components/backups/restore-progress.jsx");
  assert.match(progress, /statusRef\.current\?\.\(next\.status, next\.id\)/);
  assert.match(progress, /\}, \[inFlight, id, queued, router\]\);/);
});

test("PP-F: the show/hide password button is reachable by keyboard", () => {
  assert.doesNotMatch(read("components/ui/password-input.jsx"), /tabIndex=\{-1\}/);
});

test("WAF-A/B/C: saved state in the badge, a real no-access page, no false all-clear", () => {
  const section = read("components/applications/firewall/firewall-section.jsx");
  assert.match(section, /const live = justSaved \?\? saved;/);
  assert.match(section, /const blocking = live\.enabled && live\.mode === "enforce";/);
  assert.match(section, /detectFailed\s*\n?\s*\? t\("detectUnknown"\)/);
  const page = read("app/(app)/applications/[application]/firewall/page.jsx");
  assert.match(page, /if \(result\.status === 403\) return <PermissionDenied title=\{t\("pageTitle"\)\} \/>;/);
  assert.match(page, /detectFailed=\{detectFailed\}/);
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.firewall.detectUnknown, l);
});

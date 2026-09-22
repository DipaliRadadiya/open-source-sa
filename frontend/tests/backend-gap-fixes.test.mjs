import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Five gaps found by diffing every backend route and response field against the
 * frontend source. Each one is pinned here because each was invisible: the
 * screens looked finished, and what was missing was a field nobody read or a
 * path nobody called.
 */

test("the certificate schema declares what the web server is actually serving", () => {
  const schema = fs.readFileSync("lib/schemas/domain.js", "utf8");

  for (const field of ["serving_stale", "served_expires_at", "served_checked_at", "stale_domains"]) {
    assert.match(schema, new RegExp(`\\b${field}\\b`), `${field} is not in the certificate schema`);
  }

  /*
   * `serving_stale` must NOT be defaulted to false. Null means nothing managed
   * to complete a handshake to look, and "we could not check" rendering as
   * "agreed" is the one wrong answer this field can give.
   */
  assert.match(
    schema,
    /serving_stale:\s*z\.boolean\(\)\.nullish\(\)/,
    "serving_stale must stay nullable — a default would turn 'unknown' into 'fine'",
  );
});

test("the stale-certificate banner renders only on a definite yes", () => {
  const ssl = fs.readFileSync("components/applications/domains/ssl-section.jsx", "utf8");
  assert.match(
    ssl,
    /cert\.serving_stale === true/,
    "a truthiness check would show the alarm for null, which means 'not checked'",
  );
  // The whole point is being able to fix it from here.
  assert.match(ssl, /runServiceAction\(webServer, "reload"\)/);

  /*
   * And the panel around it must not stay green.
   *
   * Only rendering showed this: the red "visitors see a warning" alert came out
   * INSIDE a green "HTTPS is active" frame with a healthy countdown above it.
   * The frame is what gets read first, and it was contradicting its own
   * contents. A stale certificate counts as unhealthy for the panel's tone even
   * though the file on disk is perfectly valid.
   */
  // Expiry joined it: painting the frame red as well turned the card into a
  // red box holding a red box holding red text beside a red button, which is
  // the same failure one layer up. Neutral withdraws the green claim; the
  // alert inside stays the only coloured panel.
  assert.match(ssl, /const tone = expired \|\| servingStale \? "neutral" : "good"/);
  assert.doesNotMatch(ssl, /tone === "bad"/, "no state may repaint the whole frame red");
  /*
   * Neutral, not red. Making it use the expired tone turned the frame, the
   * alert and the Remove button all red at once, and a wall of red says
   * nothing — every part of it shouts equally. The frame withdraws the green
   * claim; the alert inside stays the only coloured thing.
   */
  assert.doesNotMatch(
    ssl,
    /expired \? "border-destructive\/30 bg-destructive\/5" : "border-success/,
    "the panel tone is back to keying on expiry alone, so a stale cert reads as healthy",
  );
});

test("turning automatic cleanup off with nothing ticked deletes rather than saves", () => {
  /*
   * The API requires at least one category on EVERY save, including the
   * `enabled: false` one. Sending `categories: []` to turn it off came back
   * 422 — the panel refusing to let someone switch off a feature they had
   * switched on. DELETE is the endpoint for this and had no caller.
   */
  const card = fs.readFileSync("components/disk-cleaner/schedule-card.jsx", "utf8");
  assert.match(card, /if \(!enabled && picked\.size === 0\)/);
  assert.match(card, /await deleteCleanerSchedule\(\)/);

  // And the blocked-save reason has to name the way out, not just the rule.
  const en = JSON.parse(fs.readFileSync("messages/en.json", "utf8"));
  assert.match(
    en.diskCleaner.schedule.pickSomething,
    /off/i,
    "the reason states the rule but never says how to turn the schedule off",
  );
});

test("a failed site's reference is bounded like every other note in the cell", () => {
  /*
   * Reported from a screenshot: a 36-character UUID in mono ran out of a
   * 14%-wide Status column and into Owner. Its two sibling branches both
   * carried `max-w-52 truncate`; this one carried nothing.
   */
  const badge = fs.readFileSync("components/applications/application-status-badge.jsx", "utf8");
  const unbounded = /className="font-mono text-xs text-destructive"/;
  assert.doesNotMatch(badge, unbounded, "the reference line has no width bound again");
  assert.match(badge, /max-w-52 truncate font-mono text-xs text-destructive/);
});

test("a server with no supervisord says so before the form is filled in", () => {
  const page = fs.readFileSync("app/(app)/applications/[application]/workers/page.jsx", "utf8");
  // Absence from the services list IS the signal — ServiceManager omits a
  // service nobody ever installed — so this needs no new endpoint.
  assert.match(page, /service\.key === "supervisor"/);

  const api = fs.readFileSync("lib/api/workers.js", "utf8");
  assert.match(api, /workers\/install-supervisor/, "the only endpoint with no caller still has none");
});

test("every new string is translated in all active locales", () => {
  const locales = fs
    .readFileSync("i18n/routing.js", "utf8")
    .match(/export const locales = \[([^\]]+)\]/)[1]
    .split(",")
    .map((code) => code.trim().replace(/['"]/g, ""))
    .filter(Boolean);

  const english = JSON.parse(fs.readFileSync("messages/en.json", "utf8"));

  for (const locale of locales) {
    const m = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8"));

    for (const key of ["servingStale", "servingStaleDetail", "reloadWebServer", "reloaded", "reloadFailed", "staleDomains"]) {
      assert.ok(m.applications.domains.ssl[key], `${locale} is missing ssl.${key}`);
    }
    for (const key of ["missing", "install", "installing", "installFailed"]) {
      assert.ok(m.applications.workers.supervisor[key], `${locale} is missing supervisor.${key}`);
    }
    assert.ok(m.databases.users.passwordUnknown, `${locale} is missing users.passwordUnknown`);

    // English left in place reads as translated and is not; the parity gate
    // only checks that a key exists.
    if (locale !== "en") {
      assert.notEqual(
        m.applications.workers.supervisor.missing,
        english.applications.workers.supervisor.missing,
        `${locale} still carries the English supervisor warning`,
      );
    }
  }
});

test("Download is offered on a verified backup, which is the success state", () => {
  /*
   * Reported from a screenshot: a row whose badge said "Complete" had a
   * disabled Download whose tooltip said the backup had failed.
   *
   * The gate was `backup.status !== "completed"`. `BackupStatus` on the backend
   * is pending | running | verifying | verified | failed — there is no
   * "completed", so that test was true of EVERY backup and Download was blocked
   * on all of them, including every one that had worked.
   *
   * Restore, two files away, used "verified" correctly the whole time. One
   * screen asking the question with a literal and another with a different
   * literal is how they came to disagree, so both now go through one predicate.
   */
  const button = fs.readFileSync("components/backups/download-backup-button.jsx", "utf8");
  const schema = fs.readFileSync("lib/schemas/backup.js", "utf8");

  assert.match(button, /!backupHasArchive\(backup\.status\)/);

  /*
   * Comments stripped before the negative assertion. The file explains the old
   * broken expression in prose, so matching the raw source failed on the very
   * comment documenting the fix — a test that fails on its own explanation is
   * worse than no test, because the obvious response is to delete the words.
   */
  const code = button
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/^\s*\/\/.*$/gm, "");

  assert.doesNotMatch(
    code,
    /status !== "completed"/,
    "back to a status the API never sends, which blocks every working backup",
  );

  // The predicate must name a status the backend actually has.
  const statuses = schema.match(/BACKUP_STATUSES = \[([^\]]+)\]/)[1];
  assert.match(schema, /BACKUP_SUCCEEDED = "verified"/);
  assert.match(statuses, /"verified"/, "the success state is not in the status list");
  assert.doesNotMatch(statuses, /"completed"/, "completed is not a backup status");

  // And restore must keep agreeing with it rather than carrying its own copy.
  assert.match(schema, /RESTORABLE_STATUS = BACKUP_SUCCEEDED/);
});

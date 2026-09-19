import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna, after the `mysql 15.1` fix: "any bugs that you keep like today?" —
 * then, "i am asking for frontend".
 *
 * The backend's version of that bug was a probe that cannot fail. The
 * frontend's version is a fetcher that returns `{data: [], failed: true}` and
 * a caller that reads only the first half. Every fetcher in this panel carries
 * the flag; ten callers threw it away and rendered the empty list as a
 * sentence — "No backups have run for this site yet", "No database engine is
 * running", "Nothing has happened yet", "This site is served over plain HTTP".
 *
 * Each of those is the REASSURING answer, produced by not knowing. These pin
 * the ten, so the next screen that forgets has to walk past a red test.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

/* -------------------------------------------------------------------------
 * Wrong with no failure involved
 * ---------------------------------------------------------------------- */

test("the databases page counts the server, not the page it happens to hold", () => {
  /*
   * `databases.length` is ten, because `GET /databases` pages at ten. A server
   * with forty read "10 databases", and a search or engine filter turned it
   * into the filtered count with nothing saying so. `meta.total` was already
   * destructured two lines up and passed to the table below.
   */
  const page = strip(read("app/(app)/databases/page.jsx"));
  assert.match(page, /t\("summary\.count", \{ count: dbMeta\?\.total/);
  assert.doesNotMatch(page, /summary\.count", \{ count: databases\.length/);
});

test("the size beside it is only shown when the page IS every database", () => {
  // There is no server-side byte total, and summing this page is a real sum of
  // the wrong set. A partial sum printed as a total is the same bug in other
  // units, so it is omitted rather than qualified.
  const page = strip(read("app/(app)/databases/page.jsx"));
  assert.match(page, /const wholeList = databases\.length === \(dbMeta\?\.total/);
  assert.match(page, /wholeList \? formatBytes/);
});

/* -------------------------------------------------------------------------
 * A failed read is not a fact
 * ---------------------------------------------------------------------- */

test("the SSL tab does not claim plain HTTP over an unanswered request", () => {
  /*
   * `!cert ? "none"` covered both "no certificate" and "we could not ask", so
   * a 500 rendered "Not secured / This site is served over plain HTTP" with an
   * Enable HTTPS button on a site holding a live certificate. The fetcher's
   * own comment says the two answers must stay apart.
   */
  const page = strip(read("app/(app)/applications/[application]/domains/page.jsx"));
  assert.match(page, /certificate\.failed\s*\?\s*"unknown"/);
  assert.match(page, /certificate\.failed \? \(\s*<LoadFailed/);

  // And the tab's own icon stops asserting it too — a crossed-out padlock is
  // the same claim in 16 pixels.
  const tabs = strip(read("components/applications/domains/domains-ssl-tabs.jsx"));
  assert.match(tabs, /status === "unknown"/);
  assert.match(tabs, /ShieldQuestion/);
  for (const locale of LOCALES) {
    assert.equal(
      typeof messages[locale].applications?.domains?.tabs?.sslUnknown,
      "string",
      `${locale} tabs.sslUnknown`,
    );
  }
});

test("the visit-site link uses the server's own URL", () => {
  /*
   * The page assembled the scheme from the certificate read, so a failed
   * `GET /applications/{id}/certificate` silently downgraded an https-only
   * site's link to http://. `application.url` is the server's answer and is
   * what the list and the ⋯ menu already use — hand-assembly is how that field
   * came to exist.
   */
  const page = strip(read("app/(app)/applications/[application]/page.jsx"));
  assert.match(page, /const siteUrl =\s*application\.url \?\?/);
});

test("the backups screen never says 'never' when it could not ask", () => {
  /*
   * This is the screen whose entire job is answering "am I protected", and the
   * destructure dropped `failed` outright.
   */
  const page = strip(read("app/(app)/applications/[application]/backups/page.jsx"));
  assert.match(page, /failed: backupsFailed/);
  assert.match(page, /backupsFailed=\{backupsFailed\}/);

  const panel = strip(read("components/applications/backups/backups-panel.jsx"));
  assert.match(panel, /failed \? t\("historyFailed"\) : t\("noRuns"\)/);
  // The summary line too: "No backup has run yet" is the same claim.
  assert.match(panel, /lastBackupUnknown \? "—" : t\("neverRun"\)/);
  for (const locale of LOCALES) {
    assert.equal(
      typeof messages[locale].backups?.application?.historyFailed,
      "string",
      `${locale} historyFailed`,
    );
  }
});

test("the 'this site has no database' warning needs a successful look", () => {
  // The identical claim on the application page is already guarded with
  // `!siteDatabases.failed` and the comment "Only when we actually looked and
  // found none". The backups page raised an amber banner on a failed read.
  const panel = strip(read("components/applications/backups/backups-panel.jsx"));
  assert.match(panel, /needsDatabase && siteDatabasesKnown && siteDatabases\.length === 0/);
  const page = strip(read("app/(app)/applications/[application]/backups/page.jsx"));
  assert.match(page, /siteDatabasesKnown=\{!siteDbs\.failed\}/);
});

test("the monitor page does not report the engine down when it could not ask", () => {
  /*
   * "No database engine is running. Start or connect one first." — told to
   * someone whose database was fine, because `const { engines } = live`
   * dropped the flag. The databases page destructures `failed` from the SAME
   * call and has always rendered LoadFailed.
   */
  const page = strip(read("app/(app)/databases/monitor/page.jsx"));
  assert.match(page, /failed: enginesFailed/);
  assert.match(page, /enginesFailed \? \(\s*<LoadFailed/);
});

test("getEngines carries WHICH failure, not just that there was one", () => {
  // LoadFailed turns a 403 into "you do not have permission" and a 500 into
  // "this is ours" — but only if status and failure reach it.
  const fetcher = strip(read("lib/databases/get-databases.js"));
  assert.match(fetcher, /return \{ engines: data\?\.engines \?\? \[\], failed, status, failure \}/);
});

test("the admin audit feed does not report silence it did not observe", () => {
  /*
   * `get-activity-log.js` already says it: "an unreachable API rendered as
   * 'nothing has happened here'. On an audit log that reading is worse than
   * useless." The fetcher was fixed; this caller was not.
   */
  const feed = strip(read("components/admin/dashboard/activity-feed.jsx"));
  assert.match(feed, /failed \? t\("failed"\) : t\("empty"\)/);
  assert.match(feed, /todayKnown \? t\("today"/);
  assert.match(strip(read("app/admin/page.jsx")), /failed=\{activity\.failed\}/);
  for (const locale of LOCALES) {
    assert.equal(typeof messages[locale].admin?.feed?.failed, "string", `${locale} feed.failed`);
  }
});

test("the admin roles page branches like every other admin table", () => {
  // `failed` was passed to redirectOutOfRange on one line and dropped for
  // rendering on the next, so a failed read read as "No roles yet".
  const page = strip(read("app/admin/roles/page.jsx"));
  assert.match(page, /rolesPage\.failed \? \(\s*<LoadFailed/);
  assert.match(page, /status=\{rolesPage\.status\}/);
});

test("the access card shows a dash, not a zero, when stats did not load", () => {
  /*
   * `?? 0` turned a failed `GET /admin/dashboard` — including the 403 its own
   * fetcher anticipates — into "0 of 0 users" and "0 roles configured". The
   * impersonation row of this same component already refuses to speak without
   * an answer; two of its three rows did not.
   */
  const card = strip(read("components/admin/dashboard/people-card.jsx"));
  assert.match(card, /users\s*\?\s*t\("adminsOf"/);
  assert.match(card, /roles \? t\("rolesConfigured".*: "—"/s);
  assert.doesNotMatch(card, /roles\?\.total \?\? 0/);
});

test("the dashboard says so when it could not read the database engines", () => {
  /*
   * Mine, written an hour after fixing the bug it repeats: the engine chips
   * vanished on a failed read, leaving the dashboard disagreeing with the
   * databases page — which is the whole fault that card was just rebuilt for.
   * The no-permission case was documented; the failure case was not.
   */
  const page = strip(read("app/(app)/dashboard/page.jsx"));
  assert.match(page, /enginesFailed=\{Boolean\(engineResult\?\.failed\)\}/);
  const card = strip(read("components/dashboard/server-info-card.jsx"));
  assert.match(card, /enginesFailed \? \(/);
  assert.match(card, /t\("info\.enginesUnknown"\)/);
  // And the "None detected" fallback must not win while a failure is being
  // reported — that would replace the notice with a different wrong answer.
  assert.match(card, /runtimes\.length \|\| installedEngines\.length \|\| enginesFailed/);
  for (const locale of LOCALES) {
    assert.equal(
      typeof messages[locale].serverDashboard?.info?.enginesUnknown,
      "string",
      `${locale} info.enginesUnknown`,
    );
  }
});

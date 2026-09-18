import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * From a read-only sweep of 30 live screens. Nothing here was reported by a
 * user; all of it was the panel disagreeing with itself, which is the one class
 * of fault the build, the tests, eslint and check-i18n all pass straight over.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const { locales } = await import("../i18n/routing.js");
const table = read("components/system-users/system-users-table.jsx");
const navigation = read("lib/navigation.js");

test("the system users table drops Created before it drops its controls", () => {
  /*
   * Measured on the live panel at 1440: nine columns wanted 1222px in a 1118px
   * box, so Created was cut and Actions — the row menu, the only column that
   * DOES anything — sat entirely off the right edge. Still reachable by
   * scrolling, but a table whose controls are past the horizon reads as broken.
   *
   * After, measured with the shell's 322px accounted for:
   *   1440 → 0px over (was 104, Actions OFF)
   *   1536 → 0px over (was 8, Actions CUT)
   *   1920 → 0px over, Created returns
   */
  assert.match(table, /meta: \{ className: "hidden 2xl:table-cell" \},\s*\n\s*cell: CreatedCell/);
});

test("Home stays, because it is not derivable from the username", () => {
  /*
   * It looks like `/home/<username>` on every row of a panel-created server and
   * that is a coincidence of how CreateSystemUser builds it. `home_path` is
   * read from /etc/passwd by SystemUserDiscoverer during Server Sync, so an
   * adopted server's accounts can live anywhere — and those are exactly the
   * users who need the column.
   */
  assert.match(table, /accessorKey: "home_path"/);
  assert.doesNotMatch(table, /accessorKey: "home_path"[\s\S]{0,120}hidden/);
});

test("the fail2ban screen is called Fail2ban, everywhere", () => {
  /*
   * The sidebar and breadcrumb said "Fail2ban"; the page heading said "Attack
   * protection", and so did nine strings on it. Same shape as "8G Firewall" vs
   * "Web Firewall" and settled the OPPOSITE way, on Krishna's call: the tool's
   * own name is what people search for, so the page moved to meet the nav
   * rather than the other way round.
   *
   * No override, therefore — the catalog's own label is already right.
   */
  assert.doesNotMatch(navigation, /app_fail2ban/);

  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(!m.common?.navTitles?.app_fail2ban, `${locale} still overrides the nav label`);
    assert.equal(m.applications?.fail2ban?.pageTitle, "Fail2ban", `${locale} page title`);
  }
});

test("Fail2ban is untranslated in every locale, like SSH or sudo", () => {
  // A product name, not a word. Only the sentence around it is translated.
  const KEYS = ["empty.title", "empty.action", "state.on", "saved", "removeTitle", "createAction"];
  const get = (o, p) => p.split(".").reduce((a, k) => a?.[k], o);
  for (const locale of locales) {
    const f = JSON.parse(read(`messages/${locale}.json`)).applications.fail2ban;
    for (const key of KEYS) {
      assert.match(get(f, key), /Fail2ban/, `${locale}.${key} should name the tool`);
    }
  }
});

test("no string on that screen still says 'attack protection'", () => {
  // Nine of them did. A half-rename would leave the heading and the body
  // disagreeing again, which is the whole fault being fixed.
  for (const locale of ["en"]) {
    const f = JSON.stringify(JSON.parse(read(`messages/${locale}.json`)).applications.fail2ban);
    assert.doesNotMatch(f, /attack protection/i, `${locale} has leftovers`);
  }
});

test("English headings match their own breadcrumbs", () => {
  /*
   * The breadcrumb takes its text from the backend's nav catalog, which is
   * Title Case; three headings were sentence case and disagreed with the trail
   * directly above them. Title Case is the panel's own convention too — nine of
   * thirteen multi-word headings already use it.
   */
  const en = JSON.parse(read("messages/en.json"));
  assert.equal(en.cronJobs.title, "Cron Jobs");
  assert.equal(en.sync.title, "Server Sync");
  assert.equal(en.logs.title, "System Logs");
  assert.equal(en.activity.mine.title, "Activity Log");
});

test("Title Case was NOT imposed on the other locales", () => {
  /*
   * It is an English convention. "Registro de Actividad" is unnatural Spanish
   * and "Журнал Активности" is wrong Russian — Spanish, French, Portuguese and
   * Russian all use sentence case for headings. Applying it everywhere was the
   * first attempt and it was wrong.
   */
  const es = JSON.parse(read("messages/es.json"));
  const ru = JSON.parse(read("messages/ru.json"));
  assert.equal(es.activity.mine.title, "Registro de actividad");
  assert.equal(ru.activity.mine.title, "Журнал активности");
});

test("Activity Log has one name per locale", () => {
  // Pre-existing, and only comparable once the two English strings matched:
  // pt said "Log de atividades" in the admin nav and "Registro de atividades"
  // on the page; ru said "Журнал действий" vs "Журнал активности".
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const nav = m.admin?.nav?.activityLog;
    const page = m.activity?.mine?.title;
    if (!nav || !page) continue;
    assert.equal(nav, page, `${locale}: admin nav "${nav}" != page "${page}"`);
  }
});

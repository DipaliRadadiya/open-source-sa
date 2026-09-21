import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * A PHP version that is present but was never set up by the panel.
 *
 * On this server `openlitespeed` pulls in `lsphp83` as its own apt dependency,
 * so the list carries an 8.3 with none of the base extensions — no curl,
 * sqlite3, redis, intl or pgsql — while the 8.4 the panel installed has all of
 * them. It ran. It was offered for new applications. It looked identical to
 * the healthy version beside it, and the missing extension surfaced days later
 * inside somebody's site.
 *
 * The API had reported this the whole time: `PhpOverview` publishes
 * `missing_packages`, and `PhpController::store` short-circuits ONLY when the
 * version is installed AND complete, so the existing install endpoint already
 * repairs it. The frontend was the missing half — and the Zod schema was
 * dropping the field before any component could see it.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("the field survives the schema", () => {
  // Everything else here is unreachable without this line. Zod strips unknown
  // keys, so the panel could not have shown this state however the components
  // were written.
  const schema = read("lib/schemas/php.js");
  assert.match(schema, /missing_packages: z\.array\(z\.string\(\)\)\.nullable\(\)\.optional\(\)\.default\(\[\]\)/);
});

test("an incomplete version does not look like a healthy one", () => {
  const row = read("components/php/version-summary.jsx");
  assert.match(row, /const missingPackages = version\.missing_packages \?\? \[\]/);
  assert.match(row, /const incomplete = missingPackages\.length > 0 && !installState/);
  assert.match(row, /t\("versions\.incomplete"\)/);
});

test("it is not claimed while an install or removal is running", () => {
  /*
   * Mid-install the package list is legitimately incomplete. Saying
   * "Incomplete" there would be describing the install in progress, not a
   * server that needs attention.
   */
  const row = read("components/php/version-summary.jsx");
  assert.match(row, /missingPackages\.length > 0 && !installState/);
});

test("the missing packages are named, not counted", () => {
  // "5 packages missing" tells nobody whether their application will run.
  const row = read("components/php/version-summary.jsx");
  assert.match(row, /t\("versions\.incompleteDetail", \{ packages: missingPackages\.join\(", "\) \}\)/);
  for (const locale of LOCALES) {
    assert.match(
      messages[locale].php.versions.incompleteDetail,
      /\{packages\}/,
      `${locale} must interpolate the list`,
    );
  }
});

test("the repair lives on the version row, not in the install dialog", () => {
  /*
   * The trap. `install-version-button.jsx` disables every option that is
   * already installed — which is every version that can be in this state — so
   * a repair wired to that picker would be a control nobody can click.
   */
  const picker = read("components/runtime/install-version-button.jsx");
  assert.match(picker, /disabled=\{option\.installed\}/, "the picker still greys installed versions");

  const row = read("components/php/version-summary.jsx");
  assert.match(row, /\{!incomplete \? null : \(/);
  assert.match(row, /onClick=\{complete\}/);
  assert.match(row, /async function complete\(\)[\s\S]*?installPhpVersion\(version\.version\)/);
});

test("the create form warns about the version actually chosen", () => {
  /*
   * Where the damage happens. Marking options inside a closed dropdown warns
   * nobody; the moment worth interrupting is after the choice is made and
   * before the application is created.
   */
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /const chosenVersion = useWatch\(\{ control: form\.control, name: config\.name \}\)/);
  assert.match(form, /const missing = chosen\?\.missing_packages \?\? \[\]/);
  assert.match(form, /t\("form\.runtimeIncomplete"/);

  // It outranks the plain range hint — that says what the application needs,
  // this says the version in the box cannot deliver it.
  assert.ok(
    form.indexOf("chosenIncomplete ? (") < form.indexOf("runtimeRequirement ? ("),
    "the warning must come before the requirement line",
  );
});

test("every new string exists in all eight locales", () => {
  for (const locale of LOCALES) {
    for (const key of ["incomplete", "incompleteDetail", "completeInstall", "completing"]) {
      assert.ok(messages[locale].php.versions[key]?.trim(), `${locale} php.versions.${key}`);
    }
    assert.ok(
      messages[locale].applications.form.runtimeIncomplete?.trim(),
      `${locale} applications.form.runtimeIncomplete`,
    );
  }
});

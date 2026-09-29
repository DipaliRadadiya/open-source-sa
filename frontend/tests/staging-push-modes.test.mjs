import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { PUSH_MODES, pushStagingFormSchema } from "../lib/schemas/application-staging.js";

const root = path.join(import.meta.dirname, "..");
const backend = path.join(root, "..", "backend");

/*
 * The three push modes have to agree in three places at once: the backend
 * FormRequest that validates them, this list that renders the choices, and
 * the message catalogue that names each one. A mode present in one and absent
 * from another fails quietly — an option that cannot be picked, or one that
 * renders with a missing-translation key where its warning should be.
 */

test("the modes offered are exactly the modes the API accepts", () => {
  const request = fs.readFileSync(
    path.join(backend, "app/Http/Requests/Server/Application/PushStagingRequest.php"),
    "utf8",
  );

  const rule = request.match(/Rule::in\(\[([^\]]+)\]\)/);
  assert.ok(rule, "PushStagingRequest should validate mode with Rule::in");

  const accepted = [...rule[1].matchAll(/'([a-z]+)'/g)].map((m) => m[1]);

  assert.deepEqual([...PUSH_MODES].sort(), [...accepted].sort());
});

test("every mode has a one-line meaning and its own consequence in every locale", () => {
  /*
   * Krishna 2026-09-29: the Replaces/Deletes/Undo table under every option made
   * the dialog "too long and confusing" (research-staging-push-ui: no panel
   * surveyed shows one). Each option now says what it is in a line, and what
   * it costs is shown for the one chosen. A mode missing either in one
   * language is a hole on the most destructive screen in the panel.
   */
  for (const locale of locales) {
    const dialog = JSON.parse(fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8")).applications.staging.pushDialog;
    assert.equal(dialog.facts, undefined, `${locale} kept the facts table`);
    for (const mode of PUSH_MODES) {
      for (const key of ["label", "summary", "consequence"]) {
        assert.ok(dialog.modes[mode]?.[key], `missing modes.${mode}.${key} in ${locale}`);
      }
    }
    assert.ok(dialog.whileRunning && dialog.backupFirst.includes("<link>"), locale);
  }
});
test("the schema accepts each mode and refuses anything else", () => {
  for (const mode of PUSH_MODES) {
    assert.equal(pushStagingFormSchema.safeParse({ mode }).success, true, mode);
  }

  assert.equal(pushStagingFormSchema.safeParse({ mode: "everything" }).success, false);
  // No default: the dialog must make the operator choose, because each mode
  // destroys something different.
  assert.equal(pushStagingFormSchema.safeParse({}).success, false);
});

test("database-only names the risk that makes it not the safe middle option", () => {
  // It leaves production's files and swaps the database underneath them, so a
  // plugin or theme staging has and production does not is a blank site. The
  // copy has to say that; it is the whole reason this mode is not "gentler".
  const messages = JSON.parse(
    fs.readFileSync(path.join(root, "messages/en.json"), "utf8"),
  );
  // It moved out of the prose into its own warning row, which is the only row
  // any mode has that the other two do not — so it must not quietly vanish in
  // the move.
  const note = messages.applications.staging.pushDialog.modes.database.consequence;

  assert.ok(note, "database-only lost its blank-site warning");
  assert.match(note, /plugin/i);
  assert.match(note, /theme/i);

  const dialog = fsSrc.readFileSync(
    path.join(root, "components/applications/staging/push-staging-dialog.jsx"),
    "utf8",
  );
  assert.match(dialog, /t\(`modes\.\$\{mode\}\.consequence`\)/);
});

/* -------------------------------------------------------------------------
 * The dialog itself — Krishna: "this modal also needs ui improvements".
 *
 * One of the four things wrong with it was not a matter of taste. Measured in
 * a 700px-high window: 717px of dialog, clipped 8px at the top and 9px at the
 * bottom, with no scroll to reach either. On a shorter laptop the confirm
 * button itself goes — on the most destructive action in the panel.
 * ---------------------------------------------------------------------- */

import fsp from "node:fs";
import fsSrc from "node:fs";
const { locales } = await import("../i18n/routing.js");

const readSrc = (p) => fsp.readFileSync(p, "utf8");
const stripped = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

test("a dialog can never be taller than the window it is in", () => {
  /*
   * Fixed in the SHARED content, not in the one dialog that showed it: nothing
   * anywhere bounded the height, and ConfirmDialog — which is most of the
   * panel's confirmations — wraps this same component.
   */
  const shell = stripped(readSrc("components/ui/alert-dialog.jsx"));
  assert.match(shell, /max-h-\[calc\(100dvh-2rem\)\]/);
  assert.match(shell, /overflow-y-auto/);
  // dvh, not vh: on mobile Safari `vh` counts the area behind the browser
  // chrome, which is how a "bounded" dialog still ends up unreachable.
  assert.doesNotMatch(shell, /max-h-\[calc\(100vh/);
});

test("the irreversibility warning sits above the choice, not below it", () => {
  /*
   * "There is no way back from this" was grey body text UNDER the three
   * options — the most important sentence in the dialog, placed after the
   * decision it exists to inform.
   */
  const src = stripped(readSrc("components/applications/staging/push-staging-dialog.jsx"));
  const backup = src.indexOf('t.rich("backupFirst"');
  const choice = src.indexOf("<ChoiceField");
  assert.ok(backup > 0 && choice > 0, "both present");
  assert.ok(backup < choice, "the warning must come before the options");
});

test("one consequence box, for the chosen option only", () => {
  const src = stripped(readSrc("components/applications/staging/push-staging-dialog.jsx"));
  assert.equal((src.match(/border-destructive\/30 bg-destructive\/5/g) ?? []).length, 0);
  assert.match(src, /\{mode \? \([\s\S]{0,500}t\(`modes\.\$\{mode\}\.consequence`\)/);
});
test("the age of the copy is read before the choice it informs", () => {
  const src = stripped(readSrc("components/applications/staging/push-staging-dialog.jsx"));
  const age = src.indexOf('t("copyAge"');
  const choice = src.indexOf("<ChoiceField");
  assert.ok(age > 0 && age < choice, "age belongs with the domains, before the options");
});
test("Take a backup first opens in a new tab, so the push is not lost", () => {
  const src = readSrc("components/applications/staging/push-staging-dialog.jsx");
  assert.match(src, /href=\{`\/applications\/\$\{appId\}\/backups`\}\s*target="_blank"\s*rel="noopener noreferrer"/);
});

test("an option icon is optional, so every other ChoiceField is unchanged", () => {
  /*
   * ChoiceField is used on ten screens. Files / a database / both are three
   * different KINDS of thing, which is the case an icon helps with; a list of
   * degrees of one thing it would only decorate.
   */
  const field = stripped(readSrc("components/ui/choice-field.jsx"));
  assert.match(field, /option\.icon \? \(/, "icon must be conditional");
  const dialog = readSrc("components/applications/staging/push-staging-dialog.jsx");
  assert.match(dialog, /MODE_ICONS = \{ files: FileText, database: Database, full: Layers \}/);
});

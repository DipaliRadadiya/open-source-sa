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

test("every mode answers the same three questions in every locale", () => {
  /*
   * They were three paragraphs of 25, 58 and 34 words, each answering the same
   * three questions in a different order: what is replaced, what is destroyed,
   * whether there is any way back. Comparing three options meant extracting
   * that from prose three times.
   *
   * All eight locales, because a mode that loses one of the three rows in one
   * language is a card with a hole in it — and this is the screen where the
   * missing row would be "Undo: none".
   */
  for (const locale of locales) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const dialog = messages.applications.staging.pushDialog;

    for (const key of ["replaces", "deletes", "undo"]) {
      assert.ok(dialog.facts?.[key], `missing facts.${key} in ${locale}`);
    }

    for (const mode of PUSH_MODES) {
      assert.ok(dialog.modes[mode]?.label, `missing modes.${mode}.label in ${locale}`);
      for (const key of ["replaces", "deletes", "undo"]) {
        assert.ok(dialog.modes[mode]?.[key], `missing modes.${mode}.${key} in ${locale}`);
      }
      // The prose these replaced, so a half-migrated locale cannot sit here
      // rendering one option as a paragraph and the next as three rows.
      assert.equal(dialog.modes[mode].description, undefined, `${locale} ${mode} kept its prose`);
    }
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
  const note = messages.applications.staging.pushDialog.modes.database.note;

  assert.ok(note, "database-only lost its blank-site warning");
  assert.match(note, /plugin/i);
  assert.match(note, /theme/i);

  const dialog = fsSrc.readFileSync(
    path.join(root, "components/applications/staging/push-staging-dialog.jsx"),
    "utf8",
  );
  assert.match(dialog, /mode === "database" \? t\("modes\.database\.note"\) : null/);
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

test("the two costs are one block, not two competing banners", () => {
  // Two full-width warnings shout equally loudly and the second stops being
  // read — the same finding as the clone page's pre-flight list.
  const src = stripped(readSrc("components/applications/staging/push-staging-dialog.jsx"));
  const blocks = src.match(/border-destructive\/30 bg-destructive\/5/g) ?? [];
  assert.equal(blocks.length, 1);
});

test("the age of the copy sits beside the choice it informs", () => {
  // It was a bare grey line floating between a red panel and a heading,
  // belonging to neither. It is the fact that decides whether this push is
  // routine or a mistake.
  const src = stripped(readSrc("components/applications/staging/push-staging-dialog.jsx"));
  const label = src.indexOf('t("whatToPush")');
  const age = src.indexOf('t("copyAge"');
  const choice = src.indexOf("<ChoiceField");
  assert.ok(label < age && age < choice, "age belongs between the heading and the options");
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

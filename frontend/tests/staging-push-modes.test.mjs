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

test("every mode has a label and a description in every locale", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const modes = messages.applications.staging.pushDialog.modes;

    for (const mode of PUSH_MODES) {
      assert.ok(modes[mode]?.label, `missing modes.${mode}.label in ${locale}`);
      assert.ok(modes[mode]?.description, `missing modes.${mode}.description in ${locale}`);
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
  const description = messages.applications.staging.pushDialog.modes.database.description;

  assert.match(description, /plugin/i);
  assert.match(description, /theme/i);
});

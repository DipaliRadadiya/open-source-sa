import test from "node:test";
import assert from "node:assert/strict";
import { errorTarget } from "../lib/api/error-target.js";

// Where a 422 field error is shown. Getting this wrong is silent: the message
// goes into form state, nothing renders it, and the user presses Save again.

// One over-long exclude line came back as `file_excludes.3`. Set there, react
// -hook-form stores it nested, so the <FormMessage> bound to the list reads
// `.message` off an object and renders nothing.
test("an error on a list item lands on the list, which is what renders", () => {
  const fields = { file_excludes: ["a", "b", "c", "d"] };
  assert.equal(errorTarget("file_excludes.3", fields, fields), "file_excludes");
  assert.equal(errorTarget("database_excludes.0", { database_excludes: [] }, { database_excludes: [] }), "database_excludes");
});

// A genuinely nested field has its own input and keeps its own error.
test("a nested field keeps its own error", () => {
  const shape = { settings: { token: "" } };
  assert.equal(errorTarget("settings.token", shape, shape), "settings.token");
});

test("a plain field is unchanged", () => {
  assert.equal(errorTarget("name", { name: "" }, { name: "" }), "name");
});

// The firewall case: sent, but the form's input is called something else.
test("a key the form does not have is refused", () => {
  assert.equal(errorTarget("port_from", { ports: "" }, { port_from: 22 }), null);
});

// The cron case: a real field, on the branch the user is not using.
test("a key the request did not carry is refused", () => {
  const fields = { username: "", system_user_id: "" };
  assert.equal(errorTarget("username", fields, { system_user_id: 4 }), null);
});

/*
 * The worker case, and the one the three tests above cannot reach: a field that
 * IS in the form's values and IS in the body, and still has no control.
 *
 * A worker's `kind` is set by picking a preset, never by an input. So the API's
 * "you can't run Horizon and a queue worker on the same app" passed both checks
 * and was stored against nothing — Save did nothing at all, in both dialogs,
 * with no message anywhere on screen.
 */
test("a field the form holds but never renders is refused", () => {
  const fields = { name: "queue", kind: "horizon" };
  const sent = { name: "queue", kind: "horizon" };
  assert.equal(errorTarget("kind", fields, sent), "kind", "without the list it still lands on kind");
  assert.equal(errorTarget("kind", fields, sent, ["kind"]), null);
  // Naming one field does not silence its neighbours.
  assert.equal(errorTarget("name", fields, sent, ["kind"]), "name");
});

test("both worker dialogs declare kind unrendered and render the fallback", async () => {
  const { readFileSync } = await import("node:fs");
  for (const file of ["create-worker-dialog.jsx", "edit-worker-dialog.jsx"]) {
    const source = readFileSync(
      new URL(`../components/applications/workers/${file}`, import.meta.url),
      "utf8",
    );
    assert.match(source, /unrendered: \["kind"\]/, `${file} does not declare kind`);
    assert.match(source, /formError: true/, `${file} would toast instead of holding the message`);
    // Declaring it is only half: something has to paint root.server.
    assert.match(source, /errors\.root\?\.server\?\.message/, `${file} never reads the form error`);
    assert.match(source, /\{serverError\}/, `${file} never renders the form error`);
    // A refusal from the previous press, sitting above a worker since changed.
    assert.match(source, /clearErrors\("root\.server"\)/, `${file} keeps a stale form error`);
  }
});

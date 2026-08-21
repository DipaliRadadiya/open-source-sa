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

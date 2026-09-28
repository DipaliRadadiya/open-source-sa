import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");

test("login page says the session expired when the tab still holds a place to return to", () => {
  assert.match(read("app/(auth)/login/page.jsx"), /<SessionExpiredNote \/>\s*<LoginForm \/>/);
  const note = read("components/forms/session-expired-note.jsx");
  assert.match(note, /useSyncExternalStore\(noSubscribe, readExpired, readOnServer\)/);
  assert.match(read("lib/auth/last-path.js"), /export function peekRememberedPath\(\)/);
  // a deliberate sign-out still clears the place, so the note stays away then
  assert.match(read("components/sections/user-menu.jsx"), /forgetRememberedPath\(\);/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) assert.ok(JSON.parse(read(`messages/${l}.json`)).auth.sessionExpired, l);
});

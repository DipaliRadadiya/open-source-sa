import { test } from "node:test";
import assert from "node:assert/strict";
import { passwordRules, passwordMeetsRules } from "../lib/auth/password-rules.js";
import { registerSchema } from "../lib/schemas/auth.js";
import { changePasswordSchema } from "../lib/schemas/account.js";

const ok = (value) => Object.fromEntries(passwordRules(value).map((r) => [r.key, r.ok]));

test("each rule reports on its own", () => {
  assert.deepEqual(ok(""), { length: false, case: false, number: false });
  assert.deepEqual(ok("short1A"), { length: false, case: true, number: true });
  assert.deepEqual(ok("alllowercase1"), { length: true, case: false, number: true });
  assert.deepEqual(ok("ALLUPPERCASE1"), { length: true, case: false, number: true });
  assert.deepEqual(ok("NoDigitsHere"), { length: true, case: true, number: false });
  assert.deepEqual(ok("CorrectHorse1"), { length: true, case: true, number: true });
});

test("a non-string never throws the form away", () => {
  // field.value is undefined on first render.
  assert.deepEqual(ok(undefined), { length: false, case: false, number: false });
  assert.deepEqual(ok(null), { length: false, case: false, number: false });
});

test("exactly ten characters passes — the boundary the copy promises", () => {
  assert.equal(passwordRules("Abcdefghi1")[0].ok, true);
  assert.equal(passwordRules("Abcdefgh1")[0].ok, false);
});

// The point of the shared module: a checklist that says "you're fine" while the
// schema rejects the submit is worse than no checklist.
test("the checklist agrees with both schemas it describes", () => {
  const candidates = [
    "",
    "short1A",
    "alllowercase1",
    "ALLUPPERCASE1",
    "NoDigitsHere",
    "Abcdefghi1",
    "CorrectHorse1",
    "  Padded  1A  ",
  ];

  for (const password of candidates) {
    const expected = passwordMeetsRules(password);

    const register = registerSchema.safeParse({
      name: "A",
      username: "a",
      password,
      password_confirmation: password,
    });
    assert.equal(register.success, expected, `register disagreed on ${JSON.stringify(password)}`);

    const change = changePasswordSchema.safeParse({
      current_password: "whatever",
      password,
      password_confirmation: password,
    });
    assert.equal(change.success, expected, `change disagreed on ${JSON.stringify(password)}`);
  }
});

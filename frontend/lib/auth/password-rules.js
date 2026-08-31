/**
 * The password requirements, as the reader sees them.
 *
 * These mirror `registerSchema` and `changePasswordSchema` — both enforce ten
 * characters, both cases and a digit. Kept here rather than inline in a form so
 * the checklist and the validation cannot drift apart silently, and so the
 * agreement between them is something a test can assert.
 *
 * Upper and lower case are two regexes in Zod but one line here: they fail
 * together in practice, and splitting them makes the list read as busywork.
 */
export function passwordRules(value) {
  const password = typeof value === "string" ? value : "";

  return [
    { key: "length", ok: password.length >= 10 },
    { key: "case", ok: /[a-z]/.test(password) && /[A-Z]/.test(password) },
    { key: "number", ok: /[0-9]/.test(password) },
  ];
}

export function passwordMeetsRules(value) {
  return passwordRules(value).every((rule) => rule.ok);
}

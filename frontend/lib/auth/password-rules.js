/**
 * The password requirements, as the reader sees them.
 *
 * The server publishes the policy on `GET /basic-info`, so the rules shown are
 * the rules enforced. They were hardcoded here first, which held only for as
 * long as nobody changed the policy — the day `requires_symbol` is switched on,
 * a hardcoded checklist tells someone their password is fine while the server
 * rejects it.
 *
 * The defaults below are the shipped policy, used when the endpoint could not
 * be read. Being slightly out of date is recoverable; showing no rules at all
 * on a field that has them is not.
 */
export const DEFAULT_PASSWORD_POLICY = {
  min_length: 10,
  requires_mixed_case: true,
  requires_number: true,
  requires_symbol: false,
};

const SYMBOL = /[^A-Za-z0-9]/;

/**
 * Which rules apply, and whether this value satisfies each.
 *
 * Only the rules the policy actually asks for are returned — a checklist line
 * that can never fail is noise, and one the server does not enforce is a lie.
 * Upper and lower case stay one line: they fail together in practice, and
 * splitting them makes the list read as busywork.
 */
export function passwordRules(value, policy = DEFAULT_PASSWORD_POLICY) {
  const password = typeof value === "string" ? value : "";
  const active = { ...DEFAULT_PASSWORD_POLICY, ...(policy ?? {}) };
  const min = Number.isFinite(active.min_length) ? active.min_length : DEFAULT_PASSWORD_POLICY.min_length;

  const rules = [{ key: "length", ok: password.length >= min, min }];

  if (active.requires_mixed_case) {
    rules.push({ key: "case", ok: /[a-z]/.test(password) && /[A-Z]/.test(password) });
  }
  if (active.requires_number) {
    rules.push({ key: "number", ok: /[0-9]/.test(password) });
  }
  if (active.requires_symbol) {
    rules.push({ key: "symbol", ok: SYMBOL.test(password) });
  }

  return rules;
}

export function passwordMeetsRules(value, policy = DEFAULT_PASSWORD_POLICY) {
  return passwordRules(value, policy).every((rule) => rule.ok);
}

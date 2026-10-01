// The shipped policy, used only when `GET /basic-info` cannot be read.
export const DEFAULT_PASSWORD_POLICY = {
  min_length: 10,
  requires_mixed_case: true,
  requires_number: true,
  requires_symbol: false,
};

const SYMBOL = /[^A-Za-z0-9]/;

// Upper and lower case are one rule.
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

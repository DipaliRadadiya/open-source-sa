/**
 * Defaults for backend-declared site-type fields, which arrive in whatever
 * shape PHP encoded (`false`, `"false"`, `0`, `"8"`). Shared by the form-fill
 * effect and each field's Controller so the two never disagree.
 */

/** A toggle's value as a real boolean (`Boolean("false")` would be `true`). */
export function toggleValue(value) {
  if (typeof value === "string") return !["", "0", "false"].includes(value.trim().toLowerCase());
  return Boolean(value);
}

/**
 * `undefined` means "no default declared", distinct from `false` or `0`.
 * Never `""`: a `<Select>` given `""` reports a change to `""`.
 */
export function declaredDefault(config) {
  if (config.default == null || config.default === "") return undefined;
  if (config.type === "toggle") return toggleValue(config.default);
  if (config.type === "number") return Number(config.default);
  return String(config.default);
}

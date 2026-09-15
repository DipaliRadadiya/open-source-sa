/**
 * The default value a backend-declared field starts with.
 *
 * Site-type fields arrive from the API as JSON, so a default reaches us in
 * whatever shape PHP encoded it — `false`, `"false"`, `0`, `"8"`. The control
 * that renders it wants a real boolean or a real number, and the API wants the
 * same thing back.
 *
 * Read in two places that must not disagree: the effect that fills the form
 * after a site type is chosen, and each field's Controller, which needs the
 * value on its FIRST render. Splitting the coercion between them is how they
 * start arguing about whether a default is `8` or `"8"`.
 */

/**
 * A toggle's value as a real boolean.
 *
 * `Boolean()` is wrong for two of the shapes above: `Boolean("false")` is
 * `true`, which drew the switch ON while the field still held a string the API
 * rejects with "must be true or false".
 */
export function toggleValue(value) {
  if (typeof value === "string") return !["", "0", "false"].includes(value.trim().toLowerCase());
  return Boolean(value);
}

/**
 * `undefined` means "no default declared" — distinct from a default of `false`
 * or `0`, both of which are real answers a field can start on.
 *
 * A Controller given `undefined` keeps react-hook-form's own behaviour instead
 * of being pinned to an empty string, which matters: a `<Select>` handed `""`
 * is not a select with no value, it is a select that will tell you its value
 * changed to `""`.
 */
export function declaredDefault(config) {
  if (config.default == null || config.default === "") return undefined;
  if (config.type === "toggle") return toggleValue(config.default);
  if (config.type === "number") return Number(config.default);
  return String(config.default);
}

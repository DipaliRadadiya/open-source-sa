// Where a 422 field error shows, or null when no control on screen renders it.
// `unrendered`: fields in form values with no control (e.g. a preset's `kind`).
export function errorTarget(field, fields = {}, sent = {}, unrendered = []) {
  // Nested keys arrive dotted (`settings.token`); the root is what was sent.
  const parts = field.split(".");
  const root = parts[0];

  if (unrendered.includes(root)) return null;

  const rendered = Object.prototype.hasOwnProperty.call(fields, root);
  const wasSent = Object.prototype.hasOwnProperty.call(sent, root);

  // Both checks: a sent key may have a differently named input, and a form
  // value may belong to a branch that was not sent.
  if (!rendered || !wasSent) return null;

  // `file_excludes.3` folds up to the list; only a numeric last segment folds,
  // so `settings.token` keeps its own error.
  if (parts.length > 1 && /^\d+$/.test(parts.at(-1))) {
    return parts.slice(0, -1).join(".");
  }

  return field;
}

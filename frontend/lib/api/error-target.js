/**
 * Where a single 422 field error should be shown, or null when no control on
 * screen would render it (the caller then shows it elsewhere). An error set on
 * a name nothing renders is silently lost.
 *
 * `fields` is the form's value object, `sent` is the submitted body.
 * `unrendered` lists fields held in form values with no control on screen
 * (e.g. a worker's `kind`, set by a preset); this cannot be detected here.
 */
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

  // A list item error (`file_excludes.3`) folds up to the list, whose
  // <FormMessage> would otherwise read `.message` off a nested object. Only a
  // numeric last segment folds; `settings.token` keeps its own error.
  if (parts.length > 1 && /^\d+$/.test(parts.at(-1))) {
    return parts.slice(0, -1).join(".");
  }

  return field;
}

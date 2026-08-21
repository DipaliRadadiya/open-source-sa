/**
 * Where a single 422 field error should be shown.
 *
 * Pure, and its own module, because getting this wrong is silent: the message
 * is written into form state, nothing renders it, and the user presses Save
 * over and over against a form that never explains itself. Three real bugs
 * came from the three answers below.
 *
 * `fields` is the form's own value object, `sent` is the submitted body.
 */
export function errorTarget(field, fields = {}, sent = {}) {
  // Nested keys arrive dotted (`settings.token`); the root is what was sent.
  const parts = field.split(".");
  const root = parts[0];

  const rendered = Object.prototype.hasOwnProperty.call(fields, root);
  const wasSent = Object.prototype.hasOwnProperty.call(sent, root);

  // BOTH tests, because each catches a different disappearance.
  //
  // Sent but not in the form: the firewall dialog sends `port_from` while its
  // input is called `ports`, so setError wrote to a name nothing renders.
  //
  // In the form but not sent: the cron dialog sends `system_user_id` and the
  // API answers on `username` — a real field, on the branch the user is not
  // looking at.
  if (!rendered || !wasSent) return null;

  // An error on ONE ITEM of a list arrives as `file_excludes.3`. Setting it
  // there stores it nested, so `errors.file_excludes` becomes `{ 3: {...} }`
  // and the <FormMessage> bound to the list reads `.message` off an object,
  // gets undefined, and renders nothing. The control is the list, so that is
  // where the message belongs.
  //
  // Only a NUMERIC last segment folds up: `settings.token` is a real nested
  // field with its own input and keeps its own error.
  if (parts.length > 1 && /^\d+$/.test(parts.at(-1))) {
    return parts.slice(0, -1).join(".");
  }

  return field;
}

export function errorTarget(field, fields = {}, sent = {}, unrendered = []) {
  const parts = field.split(".");
  const root = parts[0];

  if (unrendered.includes(root)) return null;

  const rendered = Object.prototype.hasOwnProperty.call(fields, root);
  const wasSent = Object.prototype.hasOwnProperty.call(sent, root);

  if (!rendered || !wasSent) return null;

  if (parts.length > 1 && /^\d+$/.test(parts.at(-1))) {
    return parts.slice(0, -1).join(".");
  }

  return field;
}

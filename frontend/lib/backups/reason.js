// An unknown reason comes back as the raw `backup.errors.<reason>` key, replaced with `fallback`.
export function reasonText(title, fallback) {
  if (!title) return null;
  return /^backup\.(errors|restore_errors)\./.test(title) ? fallback : title;
}

// The storage's own answer ("The destination rejected the credentials.") leads: the cell
// clamps to two lines, and the step that failed is the less useful half.
export function backupFailureText(backup, fallback) {
  const step = reasonText(backup?.reason_title, fallback);
  return backup?.error_class_title ? [backup.error_class_title, step].filter(Boolean).join(" ") : step;
}

// `last_backup_at` is the newest verified backup still kept, so null means nothing to restore from.
export function nothingKept(target) {
  return Boolean(target) && !target.last_backup_at;
}

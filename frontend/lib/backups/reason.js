export function reasonText(title, fallback) {
  if (!title) return null;
  return /^backup\.(errors|restore_errors)\./.test(title) ? fallback : title;
}

export function backupFailureText(backup, fallback) {
  const step = reasonText(backup?.reason_title, fallback);
  return backup?.error_class_title ? [backup.error_class_title, step].filter(Boolean).join(" ") : step;
}

export function nothingKept(target) {
  return Boolean(target) && !target.last_backup_at;
}

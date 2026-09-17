/**
 * The one mode a selection shares, or null when it has no single answer.
 *
 * The bulk Permissions dialog started at a hardcoded "644" regardless of what
 * was selected. With one folder picked that opened on 644 — "Read-only, the
 * usual choice for files" — over a directory that was actually 755, and saving
 * it removes the execute bit a folder needs to be opened at all. Selecting
 * `wp-admin` and pressing Save was enough to take the WordPress admin down.
 *
 * Null means "these genuinely differ", which is a real state and a different
 * sentence from "current is 644": the caller says they differ rather than
 * naming one. Null is also the answer for an empty selection and for entries
 * the listing sent no mode for, because inventing a current value is the
 * failure being fixed.
 */
export function sharedMode(files = []) {
  const modes = new Set();
  for (const file of files) {
    const mode = file?.mode;
    // An entry with no mode means the answer is unknown, not that the rest
    // agree — a symlink's target is not this file's permissions.
    if (!mode) return null;
    modes.add(String(mode));
    if (modes.size > 1) return null;
  }
  return modes.size === 1 ? [...modes][0] : null;
}

/** The selected rows, in the listing's own order. */
export function selectedFiles(files = [], paths = []) {
  const wanted = new Set(paths);
  return files.filter((file) => wanted.has(file.path));
}

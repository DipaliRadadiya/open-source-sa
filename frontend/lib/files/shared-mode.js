// Null when modes differ or any is unknown. Never invent a default: a wrong mode breaks the site when saved.
export function sharedMode(files = []) {
  const modes = new Set();
  for (const file of files) {
    const mode = file?.mode;
    // No mode means unknown (e.g. a symlink), not agreement.
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

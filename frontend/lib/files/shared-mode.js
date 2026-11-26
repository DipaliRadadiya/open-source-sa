// Never guess a mode; saving a wrong one can break the site.
export function sharedMode(files = []) {
  const modes = new Set();
  for (const file of files) {
    const mode = file?.mode;
    if (!mode) return null;
    modes.add(String(mode));
    if (modes.size > 1) return null;
  }
  return modes.size === 1 ? [...modes][0] : null;
}

export function selectedFiles(files = [], paths = []) {
  const wanted = new Set(paths);
  return files.filter((file) => wanted.has(file.path));
}

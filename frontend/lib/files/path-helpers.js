// Small pure helpers shared by the Rename/Copy/Compress/Extract dialogs — all
// of them need to compute a sensible default target from a relative path.

export function dirname(path) {
  const i = path.lastIndexOf("/");
  return i === -1 ? "" : path.slice(0, i);
}

export function basename(path) {
  const i = path.lastIndexOf("/");
  return i === -1 ? path : path.slice(i + 1);
}

export function joinPath(base, name) {
  return base ? `${base}/${name}` : name;
}

// "photo.jpg" -> ["photo", ".jpg"]; "archive.tar.gz" -> ["archive", ".tar.gz"]
// (the two extensions this feature ever cares about compressing/extracting);
// "README" -> ["README", ""].
function splitExtension(name) {
  const lower = name.toLowerCase();
  if (lower.endsWith(".tar.gz")) return [name.slice(0, -7), name.slice(-7)];
  const i = name.lastIndexOf(".");
  return i <= 0 ? [name, ""] : [name.slice(0, i), name.slice(i)];
}

export function copySuggestion(path) {
  const dir = dirname(path);
  const [stem, ext] = splitExtension(basename(path));
  return joinPath(dir, `${stem}-copy${ext}`);
}

export function compressSuggestion(path) {
  const dir = dirname(path);
  const [stem] = splitExtension(basename(path));
  return joinPath(dir, `${stem}.zip`);
}

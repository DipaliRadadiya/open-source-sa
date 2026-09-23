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

// The first of `candidate(1)`, `candidate(2)`, … not already taken. A second
// Copy used to suggest the same "-copy" name as the first and be refused.
function firstFree(candidate, taken) {
  for (let n = 1; n < 100; n += 1) {
    const path = candidate(n);
    if (!taken.has(path)) return path;
  }
  return candidate(1);
}

// `taken` holds the paths already in the folder on screen.
export function copySuggestion(path, taken = new Set()) {
  const dir = dirname(path);
  const [stem, ext] = splitExtension(basename(path));
  return firstFree((n) => joinPath(dir, `${stem}-copy${n > 1 ? `-${n}` : ""}${ext}`), taken);
}

// The formats the API can write. `.tgz` is only ever read: it is the same
// container as `.tar.gz`, so offering both as choices would be two buttons for
// one thing.
export const ARCHIVE_FORMATS = [".zip", ".tar.gz"];
const READABLE_ARCHIVE_EXTENSIONS = [...ARCHIVE_FORMATS, ".tgz"];

export function archiveFormatOf(path) {
  const lower = String(path).toLowerCase();
  return READABLE_ARCHIVE_EXTENSIONS.find((ext) => lower.endsWith(ext)) ?? null;
}

// Swaps the archive extension while leaving the rest of the path alone, so
// picking a format cannot move the archive out of the folder you chose.
export function withArchiveFormat(path, format) {
  const current = archiveFormatOf(path);
  const stem = current ? path.slice(0, -current.length) : path;
  return `${stem}${format}`;
}

export function compressSuggestion(path, format = ".zip", taken = new Set()) {
  const dir = dirname(path);
  const [stem] = splitExtension(basename(path));
  return firstFree((n) => joinPath(dir, `${stem}${n > 1 ? `-${n}` : ""}${format}`), taken);
}

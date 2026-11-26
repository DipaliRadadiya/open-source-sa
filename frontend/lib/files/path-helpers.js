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

export function inFolder(value, folder) {
  if (!value) return value;
  if (value.startsWith("/")) return value.replace(/^\/+/, "");
  return value.includes("/") ? value : joinPath(folder, value);
}

export function placeTarget(typed, itemPath, defaultTarget) {
  if (typed === defaultTarget) return typed;
  return inFolder(typed, dirname(itemPath));
}

function splitExtension(name) {
  const lower = name.toLowerCase();
  if (lower.endsWith(".tar.gz")) return [name.slice(0, -7), name.slice(-7)];
  const i = name.lastIndexOf(".");
  return i <= 0 ? [name, ""] : [name.slice(0, i), name.slice(i)];
}

function firstFree(candidate, taken) {
  for (let n = 1; n < 100; n += 1) {
    const path = candidate(n);
    if (!taken.has(path)) return path;
  }
  return candidate(1);
}

export function copySuggestion(path, taken = new Set()) {
  const dir = dirname(path);
  const [stem, ext] = splitExtension(basename(path));
  return firstFree((n) => joinPath(dir, `${stem}-copy${n > 1 ? `-${n}` : ""}${ext}`), taken);
}

export const ARCHIVE_FORMATS = [".zip", ".tar.gz"];
const READABLE_ARCHIVE_EXTENSIONS = [...ARCHIVE_FORMATS, ".tgz"];

export function archiveFormatOf(path) {
  const lower = String(path).toLowerCase();
  return READABLE_ARCHIVE_EXTENSIONS.find((ext) => lower.endsWith(ext)) ?? null;
}

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

const UNOPENABLE = new Set([
  "zip", "tar", "gz", "tgz", "bz2", "tbz", "xz", "zst", "rar", "7z", "lz", "lzma",
  "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "odt", "ods", "odp",
  "mp4", "mkv", "mov", "avi", "webm", "wmv", "flv", "m4v",
  "mp3", "wav", "flac", "ogg", "oga", "m4a", "aac", "opus",
  "woff", "woff2", "ttf", "otf", "eot",
  "exe", "dll", "so", "dylib", "bin", "dat", "class", "jar", "war",
  "wasm", "pyc", "pyo", "o", "a", "obj", "deb", "rpm", "apk",
  "iso", "img", "dmg", "sqlite", "sqlite3", "db", "mdb", "accdb",
  "psd", "ai", "eps", "tif", "tiff", "raw", "heic",
]);

function extensionOf(name) {
  const base = String(name ?? "").replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

export function canOpenFile(name) {
  return !UNOPENABLE.has(extensionOf(name));
}

export const EDITOR_MAX_BYTES = 5 * 1024 * 1024;

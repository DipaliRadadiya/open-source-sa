// Extensions neither the image preview nor the text editor can render.
// By extension because the listing only has a filename; the server still
// refuses non-text files that slip through.
const UNOPENABLE = new Set([
  // archives (.tar.gz reads as "gz")
  "zip", "tar", "gz", "tgz", "bz2", "tbz", "xz", "zst", "rar", "7z", "lz", "lzma",
  // documents that are containers, not text
  "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "odt", "ods", "odp",
  // media
  "mp4", "mkv", "mov", "avi", "webm", "wmv", "flv", "m4v",
  "mp3", "wav", "flac", "ogg", "oga", "m4a", "aac", "opus",
  // fonts
  "woff", "woff2", "ttf", "otf", "eot",
  // compiled and binary payloads
  "exe", "dll", "so", "dylib", "bin", "dat", "class", "jar", "war",
  "wasm", "pyc", "pyo", "o", "a", "obj", "deb", "rpm", "apk",
  // disk images and databases
  "iso", "img", "dmg", "sqlite", "sqlite3", "db", "mdb", "accdb",
  // raw images the <img> preview cannot decode either
  "psd", "ai", "eps", "tif", "tiff", "raw", "heic",
]);

// "backup.tar.gz" -> "gz", ".env" -> "env", "README" -> "readme".
function extensionOf(name) {
  const base = String(name ?? "").replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

/**
 * Whether the name should be a link. Unopenable files render as plain text;
 * download and extract stay in the row menu.
 */
export function canOpenFile(name) {
  return !UNOPENABLE.has(extensionOf(name));
}

// Keep in step with the backend's FileBrowser::MAX_BYTES; larger files go
// straight to the Download screen.
export const EDITOR_MAX_BYTES = 5 * 1024 * 1024;

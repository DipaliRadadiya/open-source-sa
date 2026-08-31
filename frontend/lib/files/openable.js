// Extensions the panel has nothing to show for. Images open in the preview
// dialog and everything else goes to the text editor, so this list is only
// the files neither can render: archives, media, compiled output and the
// office/PDF formats that are containers rather than text.
//
// Blocking by extension rather than trying to detect text: the listing only
// ever has a filename, and the editor's server-side "not text" refusal is
// still there for the unknown ones. This just stops the round-trip for the
// cases nobody needs to make.
const UNOPENABLE = new Set([
  // archives — .tar.gz reads as "gz", which is why plain "tar" is here too
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
 * Whether clicking this name leads anywhere. A file the panel cannot open is
 * rendered as plain text instead of a link — offering the click and then
 * answering "this file isn't text" is a worse answer than not offering it.
 * Downloading and extracting are still on the row's menu.
 */
export function canOpenFile(name) {
  return !UNOPENABLE.has(extensionOf(name));
}

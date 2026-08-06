import { langs } from "@uiw/codemirror-extensions-langs";

// "photo.jpg" -> "jpg"; ".env" -> "env"; "README" -> "readme". `langs` is
// keyed directly by extension, so this is the whole mapping — no manual
// extension-to-language table to keep in sync.
function extensionOf(name) {
  const base = name.replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

export function codeLanguageFor(name) {
  const factory = langs[extensionOf(name)];
  return factory ? factory() : null;
}

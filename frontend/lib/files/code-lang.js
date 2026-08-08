import { langs } from "@uiw/codemirror-extensions-langs";

// "photo.jpg" -> "jpg"; ".env" -> "env"; "README" -> "readme". `langs` is
// keyed directly by extension, so this is the whole mapping — no manual
// extension-to-language table to keep in sync.
function extensionOf(name) {
  const base = name.replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

// INI-family extensions CodeMirror keys under a different name. Small enough
// to alias rather than build a table: the fail2ban editor writes .conf files,
// and the Files editor gets the same highlighting for free.
const ALIASES = { conf: "properties", ini: "properties", cnf: "properties" };

export function codeLanguageFor(name) {
  const extension = extensionOf(name);
  const factory = langs[ALIASES[extension] ?? extension];
  return factory ? factory() : null;
}

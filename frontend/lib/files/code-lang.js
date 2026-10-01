import { langs } from "@uiw/codemirror-extensions-langs";

// "photo.jpg" -> "jpg"; ".env" -> "env"; "README" -> "readme". `langs` is
// keyed by extension, so no separate mapping table is needed.
function extensionOf(name) {
  const base = name.replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

// INI-family extensions CodeMirror keys under a different name (e.g. fail2ban .conf).
const ALIASES = { conf: "properties", ini: "properties", cnf: "properties" };

export function codeLanguageFor(name) {
  const extension = extensionOf(name);
  const factory = langs[ALIASES[extension] ?? extension];
  return factory ? factory() : null;
}

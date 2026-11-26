import { langs } from "@uiw/codemirror-extensions-langs";

function extensionOf(name) {
  const base = name.replace(/^\./, "");
  const i = base.lastIndexOf(".");
  return (i === -1 ? base : base.slice(i + 1)).toLowerCase();
}

const ALIASES = { conf: "properties", ini: "properties", cnf: "properties" };

export function codeLanguageFor(name) {
  const extension = extensionOf(name);
  const factory = langs[ALIASES[extension] ?? extension];
  return factory ? factory() : null;
}

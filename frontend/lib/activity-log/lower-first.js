// Not for German, where every noun starts with a capital.
export function lowerFirst(text, locale = null) {
  if (typeof text !== "string" || text.length < 2) return text ?? "";
  if (typeof locale === "string" && locale.toLowerCase().startsWith("de")) return text;
  const [first, second] = [text[0], text[1]];
  if (first !== first.toUpperCase() || first === first.toLowerCase()) return text;
  if (!/\s/.test(second) && (second !== second.toLowerCase() || second === second.toUpperCase())) return text;
  return first.toLowerCase() + text.slice(1);
}

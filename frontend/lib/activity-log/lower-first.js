// Only when the second character is lowercase, so "SSH key added" stays untouched.
// Never in German: every noun starts with a capital ("Sicherungsziel", not "sicherungsziel"), so the
// German sentence puts a colon after the name instead ("qaadmin: Angemeldet 12-mal").
export function lowerFirst(text, locale = null) {
  if (typeof text !== "string" || text.length < 2) return text ?? "";
  if (typeof locale === "string" && locale.toLowerCase().startsWith("de")) return text;
  const [first, second] = [text[0], text[1]];
  if (first !== first.toUpperCase() || first === first.toLowerCase()) return text;
  // A one-letter first word ("A sauvegardé …") is lowered too; "SSH key" still is not.
  if (!/\s/.test(second) && (second !== second.toLowerCase() || second === second.toUpperCase())) return text;
  return first.toLowerCase() + text.slice(1);
}

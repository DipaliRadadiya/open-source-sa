/**
 * Lowercase the first letter so a standalone label (the API's capitalised
 * `description`) fits mid-sentence. Only when the second character is
 * lowercase, so "SSH key added" or a leading name stays untouched.
 */
export function lowerFirst(text) {
  if (typeof text !== "string" || text.length < 2) return text ?? "";
  const [first, second] = [text[0], text[1]];
  if (first !== first.toUpperCase() || first === first.toLowerCase()) return text;
  if (second !== second.toLowerCase() || second === second.toUpperCase()) return text;
  return first.toLowerCase() + text.slice(1);
}

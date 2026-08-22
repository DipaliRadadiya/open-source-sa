/**
 * Lowercase the first letter, so a sentence can be built around a label that
 * was written to stand alone.
 *
 * The feed reads "test logged in 50 times". The middle of that comes from the
 * API's localized `description`, which arrives capitalised as "Logged in"
 * because everywhere else it starts a line.
 *
 * Guarded, because plenty of these do not begin with an ordinary word: "SSH
 * key added" must not become "sSH key added", and a description opening with a
 * name or a version should keep it. So the change only happens when the second
 * character is itself lowercase — the shape of a normal capitalised sentence.
 * Anything else is returned untouched.
 */
export function lowerFirst(text) {
  if (typeof text !== "string" || text.length < 2) return text ?? "";
  const [first, second] = [text[0], text[1]];
  if (first !== first.toUpperCase() || first === first.toLowerCase()) return text;
  if (second !== second.toLowerCase() || second === second.toUpperCase()) return text;
  return first.toLowerCase() + text.slice(1);
}

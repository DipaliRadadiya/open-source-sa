/**
 * Names defined more than once in the same object, with the line each repeat
 * is on.
 *
 * Scanned from the raw text rather than the parsed object because by then the
 * evidence is gone: `JSON.parse` keeps the last of a repeated key and drops
 * the rest silently. A `reviver` does not help either — it is handed the
 * already-parsed value and walks its own keys, so it never sees the loser.
 *
 * Only enough of a scanner to know where it is: strings (so a `"` inside a
 * value cannot be mistaken for structure), and whether the next string is a
 * name or a value. Everything else is skipped as one character.
 */
export function duplicateKeys(text) {
  const found = [];
  const stack = []; // { object: boolean, names: Map<string, line> }
  let expectName = false;
  let line = 1;

  for (let i = 0; i < text.length; i++) {
    const c = text[i];

    if (c === "\n") {
      line++;
    } else if (c === '"') {
      const startedOn = line;
      let name = "";
      for (i++; text[i] !== '"'; i++) {
        if (text[i] === "\\") name += text[i++];
        if (text[i] === "\n") line++;
        name += text[i];
      }
      if (expectName) {
        const { names } = stack.at(-1);
        if (names.has(name)) found.push({ name, line: startedOn, first: names.get(name) });
        else names.set(name, startedOn);
      }
      expectName = false;
    } else if (c === "{" || c === "[") {
      stack.push({ object: c === "{", names: new Map() });
      expectName = c === "{";
    } else if (c === "}" || c === "]") {
      stack.pop();
      expectName = false;
    } else if (c === ",") {
      // In an array a comma separates values, not names.
      expectName = stack.at(-1)?.object === true;
    }
  }
  return found;
}

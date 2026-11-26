// Keys defined twice in one JSON object, with their lines (JSON.parse silently drops the repeats).
export function duplicateKeys(text) {
  const found = [];
  const stack = [];
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
      expectName = stack.at(-1)?.object === true;
    }
  }
  return found;
}

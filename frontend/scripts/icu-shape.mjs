/**
 * The skeleton of an ICU message: its arguments and tags. Argument names and types, and tag
 * names, must match English. Plural categories may differ per language; only `other` is
 * required. A real recursive parse, so plural branch text is never read as an argument.
 */

const NAME = /[A-Za-z0-9_]/;
const BRANCHED = new Set(["plural", "select", "selectordinal"]);

/**
 * `{ args: Map<name, type>, tags: Set<string>, pluralsWithoutOther: string[] }`
 *
 * `type` is "" for a plain `{name}` placeholder, otherwise the ICU keyword —
 * `plural`, `select`, `selectordinal`, `number`, `date`, `time`.
 *
 * Hand-written rather than pulled from a parser dependency: this needs the
 * argument skeleton and nothing else, and a full MessageFormat implementation
 * would be a package to keep current for a build check.
 */
export function messageShape(text) {
  const shape = { args: new Map(), tags: new Set(), pluralsWithoutOther: [] };
  parseMessage(String(text ?? ""), 0, shape);
  return shape;
}

/**
 * Consume message text from `start` until the end or an unmatched `}`.
 * Returns the index of the character that stopped it.
 */
function parseMessage(value, start, shape) {
  let i = start;

  while (i < value.length) {
    const char = value[i];

    // ICU quoting: an apostrophe before a brace makes it literal, and the quote
    // runs to the next apostrophe. Every Romance locale is full of apostrophes,
    // and "d'{name}" must not be read as an argument that is not there.
    if (char === "'" && (value[i + 1] === "{" || value[i + 1] === "}" || value[i + 1] === "'")) {
      const close = value.indexOf("'", i + 2);
      i = close === -1 ? value.length : close + 1;
      continue;
    }

    if (char === "}") return i;

    if (char === "<") {
      const match = /^<\/?([A-Za-z][A-Za-z0-9]*)\s*>/.exec(value.slice(i));
      if (match) {
        shape.tags.add(match[1]);
        i += match[0].length;
        continue;
      }
      i += 1;
      continue;
    }

    if (char === "{") {
      i = parseArgument(value, i + 1, shape);
      continue;
    }

    i += 1;
  }

  return i;
}

/** Consume one argument, `start` being just after its `{`. Returns the index after its `}`. */
function parseArgument(value, start, shape) {
  let i = skipSpace(value, start);

  let name = "";
  while (i < value.length && NAME.test(value[i])) name += value[i++];
  i = skipSpace(value, i);

  // `{}` or `{ ` with no name: not an argument, so treat the brace as text and
  // let the caller carry on rather than swallowing the rest of the message.
  if (!name) return skipToClose(value, i);

  if (value[i] !== ",") {
    record(shape, name, "");
    return value[i] === "}" ? i + 1 : i;
  }

  i = skipSpace(value, i + 1);
  let type = "";
  while (i < value.length && NAME.test(value[i])) type += value[i++];
  record(shape, name, type);
  i = skipSpace(value, i);

  if (value[i] === "}") return i + 1;
  if (value[i] !== ",") return skipToClose(value, i);
  i = skipSpace(value, i + 1);

  if (!BRANCHED.has(type)) {
    // `number`, `date`, `time`: the rest is a style, which is opaque text.
    return skipToClose(value, i);
  }

  // Branches: `keyword { message }`, repeated. The keyword is not an argument;
  // the message inside it is one, and can hold arguments of its own.
  let sawOther = false;

  while (i < value.length && value[i] !== "}") {
    let keyword = "";
    while (i < value.length && !/\s|\{|\}/.test(value[i])) keyword += value[i++];
    i = skipSpace(value, i);

    if (value[i] !== "{") {
      // Malformed, or an offset like `offset:1` we do not need. Step past.
      if (!keyword) i += 1;
      continue;
    }

    if (keyword === "other") sawOther = true;
    i = parseMessage(value, i + 1, shape);
    if (value[i] === "}") i += 1;
    i = skipSpace(value, i);
  }

  if (!sawOther) shape.pluralsWithoutOther.push(name);
  return value[i] === "}" ? i + 1 : i;
}

function skipSpace(value, i) {
  while (i < value.length && /\s/.test(value[i])) i += 1;
  return i;
}

/** Skip to just past the `}` that closes the argument we are inside. */
function skipToClose(value, i) {
  let depth = 1;
  while (i < value.length && depth > 0) {
    if (value[i] === "{") depth += 1;
    else if (value[i] === "}") depth -= 1;
    i += 1;
  }
  return i;
}

function record(shape, name, type) {
  // The same argument may appear twice, once bare and once qualified; the
  // qualified mention is the one that carries information.
  if (!shape.args.has(name) || (type && !shape.args.get(name))) shape.args.set(name, type);
}

/** Human-readable differences between two messages' shapes, or []. */
export function shapeProblems(english, translated) {
  const source = messageShape(english);
  const target = messageShape(translated);
  const problems = [];

  for (const [name, type] of source.args) {
    if (!target.args.has(name)) {
      problems.push(`{${name}} is missing`);
      continue;
    }
    const found = target.args.get(name);
    if (type !== found) {
      problems.push(
        `{${name}} is ${found || "a plain placeholder"} but English has ${type || "a plain placeholder"}`,
      );
    }
  }
  for (const name of target.args.keys()) {
    if (!source.args.has(name)) problems.push(`{${name}} is not in the English string`);
  }
  for (const tag of source.tags) if (!target.tags.has(tag)) problems.push(`<${tag}> is missing`);
  for (const tag of target.tags) if (!source.tags.has(tag)) problems.push(`<${tag}> is not in the English string`);
  for (const name of target.pluralsWithoutOther) {
    problems.push(`{${name}} has no "other" branch — ICU refuses to format it`);
  }

  return problems;
}

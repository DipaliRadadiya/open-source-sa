// The ICU skeleton of a message: argument names/types and tags must match English. Plural categories may differ; only other is required.

const NAME = /[A-Za-z0-9_]/;
const BRANCHED = new Set(["plural", "select", "selectordinal"]);

export function messageShape(text) {
  const shape = { args: new Map(), tags: new Set(), pluralsWithoutOther: [] };
  parseMessage(String(text ?? ""), 0, shape);
  return shape;
}

function parseMessage(value, start, shape) {
  let i = start;

  while (i < value.length) {
    const char = value[i];

    // An apostrophe before a brace makes it literal ("d'{name}" has no argument).
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

function parseArgument(value, start, shape) {
  let i = skipSpace(value, start);

  let name = "";
  while (i < value.length && NAME.test(value[i])) name += value[i++];
  i = skipSpace(value, i);

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
    return skipToClose(value, i);
  }

  let sawOther = false;

  while (i < value.length && value[i] !== "}") {
    let keyword = "";
    while (i < value.length && !/\s|\{|\}/.test(value[i])) keyword += value[i++];
    i = skipSpace(value, i);

    if (value[i] !== "{") {
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
  if (!shape.args.has(name) || (type && !shape.args.get(name))) shape.args.set(name, type);
}

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

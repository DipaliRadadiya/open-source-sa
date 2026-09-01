import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

import { duplicateKeys } from "../scripts/duplicate-keys.mjs";

/**
 * `validation.max500` was defined twice in the same object in all three locale
 * files. `JSON.parse` keeps the last one, so one of the two messages was dead
 * — and nothing caught it: the key set was identical across locales, the key
 * resolved, the build was green, the i18n check passed.
 *
 * The scanner reads the raw text because the parsed object no longer knows.
 * These cases are the ones that would fool a naive `grep '"key":'`.
 */
const CASES = [
  ["a plain duplicate", '{"a":"1","a":"2"}', 1],
  ["distinct names", '{"a":"1","b":"2"}', 0],
  ["the same name in two different objects", '{"x":{"a":1},"y":{"a":2}}', 0],
  ["a value that contains a quoted name", '{"a":"\\"b\\": 1","b":2}', 0],
  ["a comma inside a value", '{"a":"one, two","b":"x"}', 0],
  ["a repeat after a nested object", '{"a":{"z":1},"a":2}', 1],
  ["array entries are values, not names", '{"a":["x","x"]}', 0],
  ["a repeat after an array", '{"a":["x","y"],"a":2}', 1],
  ["a brace inside a value", '{"a":"{ not real }","a":2}', 1],
  ["a colon inside a value", '{"a":"http://x","b":1}', 0],
  ["a value ending in an escaped backslash", '{"a":"back\\\\","a":2}', 1],
  ["three copies are two problems", '{"a":1,"a":2,"a":3}', 2],
];

for (const [name, json, expected] of CASES) {
  test(name, () => {
    JSON.parse(json); // the fixture has to be valid JSON itself
    assert.equal(duplicateKeys(json).length, expected);
  });
}

test("it reports both line numbers", () => {
  const [hit] = duplicateKeys('{\n  "a": 1,\n  "b": 2,\n  "a": 3\n}');
  assert.deepEqual({ name: hit.name, first: hit.first, line: hit.line }, {
    name: "a",
    first: 2,
    line: 4,
  });
});

test("no locale file has a duplicate key", () => {
  for (const file of readdirSync("messages").filter((f) => f.endsWith(".json"))) {
    const found = duplicateKeys(readFileSync(`messages/${file}`, "utf8"));
    assert.deepEqual(found, [], `${file}: ${JSON.stringify(found)}`);
  }
});

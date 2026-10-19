import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { IntlMessageFormat } from "intl-messageformat";

const root = path.join(import.meta.dirname, "..");
const message = (locale) => JSON.parse(fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8")).admin.feed.sentenceRepeated;
const say = (locale, count) => new IntlMessageFormat(message(locale), locale).format({ who: "a", what: "b", count });

test("a repeated activity counts in Russian plural forms (2 раза, 5 раз, 22 раза)", () => {
  assert.equal(say("ru", 2), "a b 2 раза");
  assert.equal(say("ru", 5), "a b 5 раз");
  assert.equal(say("ru", 21), "a b 21 раз");
  assert.equal(say("ru", 22), "a b 22 раза");
  assert.equal(say("en", 3), "a b 3 times");
});

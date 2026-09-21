import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

/*
 * `apiMessage(error, fallback)` prints the API's own message when there is
 * one, and the fallback when there is not. So the fallback is what a reader
 * sees on exactly the failures nobody anticipated — and it was a SUCCESS
 * sentence in one place:
 *
 *   toast.error(apiMessage(error, t("redis.saved")))
 *
 * Removing the Redis password could fail and put "Redis settings saved." on
 * screen in a red toast. Red with reassuring words does not read as a failure;
 * it reads as a panel that does not know what it did, which is the same fault
 * as a failed read rendered as a fact.
 *
 * 93 other call sites all pass a *Failed key. This pins that, so the outlier
 * cannot come back quietly.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const en = JSON.parse(read("messages/en.json"));

const files = [];
const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!/node_modules|\.next/.test(p)) walk(p);
    } else if (p.endsWith(".jsx") || p.endsWith(".js")) files.push(p);
  }
};
walk("components");
walk("app");

const resolve = (ns, key) => {
  const full = key.startsWith(".") ? key.slice(1) : ns ? `${ns}.${key}` : key;
  let node = en;
  for (const part of full.split(".")) {
    if (node && typeof node === "object" && part in node) node = node[part];
    else return null;
  }
  return typeof node === "string" ? node : null;
};

test("no failure message falls back to a string the panel uses to report success", () => {
  const offenders = [];
  for (const file of files) {
    const src = read(file);
    const namespaces = [...src.matchAll(/useTranslations\(\s*"([^"]+)"\s*\)/g)].map((m) => m[1]);

    // Keys this file announces success with.
    const success = new Set(
      [...src.matchAll(/toast\.success\(\s*t\w*\("([^"]+)"/g)].map((m) => m[1]),
    );
    if (!success.size) continue;

    for (const m of src.matchAll(/apiMessage\([^,]+,\s*t\w*\("([^"]+)"/g)) {
      if (!success.has(m[1])) continue;
      // Same key, same file, used both ways — resolve it so the failure names
      // the sentence a reader would actually have seen.
      const text = namespaces.map((ns) => resolve(ns, m[1])).find(Boolean) ?? m[1];
      offenders.push(`${file}: apiMessage falls back to "${text}" (a success message)`);
    }
  }
  assert.deepEqual(offenders, [], `\n${offenders.join("\n")}`);
});

test("removing the Redis password has a failure of its own, in every locale", () => {
  const src = read("components/settings/redis-form.jsx");
  assert.match(src, /toast\.success\(t\("redis\.removed"\)\)/);
  assert.match(src, /apiMessage\(error, t\("redis\.removeFailed"\)\)/);

  const locales = read("i18n/routing.js")
    .match(/export const locales = \[([^\]]+)\]/)[1]
    .split(",")
    .map((c) => c.trim().replace(/['"]/g, ""))
    .filter(Boolean);
  for (const locale of locales) {
    const redis = JSON.parse(read(`messages/${locale}.json`)).settings.performance.redis;
    assert.ok(redis.removeFailed?.trim(), `${locale} settings.performance.redis.removeFailed`);
    assert.notEqual(redis.removeFailed, redis.removed, `${locale}: the failure repeats the success`);
  }
});

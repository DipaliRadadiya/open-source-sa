import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { redisSettingsSchema } from "../lib/schemas/settings.js";

const read = (p) => readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 24 Nov, user pass on .111: the server ran allkeys-lru, the form pre-selected it, and saving
// only a new memory limit answered 422 on a field nobody touched.
test("the policies the API refuses are read, and an older API without them still parses", () => {
  const base = { maxmemory: "268435456", maxmemory_policy: "allkeys-lru", has_password: true };
  assert.deepEqual(redisSettingsSchema.parse({ ...base, unsafe_policies: ["allkeys-lru"] }).unsafe_policies, ["allkeys-lru"]);
  assert.deepEqual(redisSettingsSchema.parse(base).unsafe_policies, []);
});

test("refused policies are not offered unless the server runs one now, which is flagged before Save", () => {
  const form = read("components/settings/redis-form.jsx");
  assert.match(form, /REDIS_POLICIES\.filter\(\(policy\) => !unsafe\.includes\(policy\) \|\| policy === defaults\.maxmemory_policy\)/);
  assert.match(form, /\{policies\.map\(\(policy\) => \(/);
  assert.match(form, /error=\{unsafe\.includes\(field\.value\) \? t\("redis\.unsafePolicy"\) : undefined\}/);
  assert.match(form, /if \(unsafe\.includes\(values\.maxmemory_policy\)\) \{\s*form\.setError\("maxmemory_policy"/);
  for (const locale of LOCALES) {
    assert.ok(JSON.parse(read(`messages/${locale}.json`)).settings.performance.redis.unsafePolicy, locale);
  }
});

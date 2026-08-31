import { test } from "node:test";
import assert from "node:assert/strict";
import { redisSettingsSchema, settingsResponseSchema } from "../lib/schemas/settings.js";

// A real payload from a server whose Redis config the panel could not read.
// `has_password: null` used to fail the whole settings response, so every tab
// on the Settings page rendered "This part could not be loaded".
const UNREADABLE = {
  maxmemory: "0",
  maxmemory_policy: "noeviction",
  has_password: null,
  password_manageable: true,
  running: true,
  memory_used: null,
  memory_used_human: null,
};

test("an unreadable Redis config does not take down the settings page", () => {
  assert.equal(redisSettingsSchema.safeParse(UNREADABLE).success, true);

  const whole = settingsResponseSchema.safeParse({
    settings: {
      general: { timezone: "Etc/UTC", ntp: true, clock_synchronized: true, hostname: "h" },
      redis: UNREADABLE,
    },
    last_changed: {},
  });
  assert.equal(whole.success, true);
});

test("the three password states stay distinguishable", () => {
  const p = (v) => redisSettingsSchema.parse({ ...UNREADABLE, has_password: v }).has_password;
  assert.equal(p(true), true);   // one is set
  assert.equal(p(false), false); // none is set
  assert.equal(p(null), null);   // could not look — NOT the same as false
});

test("the stored password is accepted when the server sends it", () => {
  const parsed = redisSettingsSchema.parse({ ...UNREADABLE, has_password: true, password: "s3cret" });
  assert.equal(parsed.password, "s3cret");
  // Null when not set, unreadable, or the caller may not have it.
  assert.equal(redisSettingsSchema.parse({ ...UNREADABLE, password: null }).password, null);
  // Absent on an older backend.
  assert.equal(redisSettingsSchema.parse(UNREADABLE).password ?? null, null);
});

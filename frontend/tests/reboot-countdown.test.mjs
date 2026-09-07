import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import {
  deadlineFrom,
  remainingSeconds,
  splitRemaining,
} from "../lib/settings/reboot-countdown.js";

const root = path.join(import.meta.dirname, "..");

test("the deadline is anchored from the server's measurement, not a parsed time", () => {
  assert.equal(deadlineFrom(60, 1_000_000), 1_060_000);
  assert.equal(deadlineFrom(0, 1_000_000), 1_000_000);
});

test("a negative measurement cannot push the deadline into the past", () => {
  // systemd leaves the scheduled file in place for the moments between the
  // deadline passing and the machine going down.
  assert.equal(deadlineFrom(-30, 1_000_000), 1_000_000);
});

test("remaining time is a subtraction against the deadline, so a throttled tab is still right", () => {
  const deadline = deadlineFrom(600, 0);

  // A background tab that got one tick in five minutes: a decrementing counter
  // would say 599, the deadline says what is actually left.
  assert.equal(remainingSeconds(deadline, 300_000), 300);
  assert.equal(remainingSeconds(deadline, 0), 600);
});

test("remaining time never runs backwards past zero", () => {
  const deadline = deadlineFrom(60, 0);

  assert.equal(remainingSeconds(deadline, 60_000), 0);
  assert.equal(remainingSeconds(deadline, 120_000), 0);
});

test("the first second reads as the full delay rather than flicking down", () => {
  const deadline = deadlineFrom(60, 0);

  assert.equal(remainingSeconds(deadline, 1), 60);
  assert.equal(remainingSeconds(deadline, 1000), 59);
});

test("seconds split into the parts a person reads", () => {
  assert.deepEqual(splitRemaining(0), { hours: 0, minutes: 0, seconds: 0 });
  assert.deepEqual(splitRemaining(45), { hours: 0, minutes: 0, seconds: 45 });
  assert.deepEqual(splitRemaining(90), { hours: 0, minutes: 1, seconds: 30 });
  assert.deepEqual(splitRemaining(3600), { hours: 1, minutes: 0, seconds: 0 });
  assert.deepEqual(splitRemaining(5445), { hours: 1, minutes: 30, seconds: 45 });
});

test("hours are not capped at a day", () => {
  // `shutdown -r` takes any delay. A restart scheduled a week out from a shell
  // must not wrap around to a comfortable-looking small number.
  assert.deepEqual(splitRemaining(200_000), {
    hours: 55,
    minutes: 33,
    seconds: 20,
  });
});

test("a negative count is clamped rather than rendered", () => {
  assert.deepEqual(splitRemaining(-5), { hours: 0, minutes: 0, seconds: 0 });
});

test("every countdown message the component can pick exists in all three locales", () => {
  const component = fs.readFileSync(
    path.join(root, "components/settings/reboot-countdown.jsx"),
    "utf8",
  );

  const used = [...component.matchAll(/\bt\("([a-zA-Z]+)"/g)].map(([, key]) => key);

  assert.ok(used.length >= 4, "expected the countdown to use its message keys");

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const reboot = messages.settings.maintenance.reboot;

    for (const key of used) {
      assert.equal(
        typeof reboot[key],
        "string",
        `${locale}.json is missing settings.maintenance.reboot.${key}`,
      );
    }
  }
});

test("the status schema demands the field the countdown is built on", () => {
  // Zod strips keys it was not told about. An optional seconds_remaining that
  // stopped arriving would take the countdown off the screen silently.
  const schema = fs.readFileSync(
    path.join(root, "lib/schemas/settings.js"),
    "utf8",
  );

  const line = schema
    .split("\n")
    .find((l) => l.includes("seconds_remaining:"));

  assert.ok(line, "rebootStatusSchema must declare seconds_remaining");
  assert.ok(
    !line.includes(".optional()"),
    "seconds_remaining must be required, not optional",
  );
});

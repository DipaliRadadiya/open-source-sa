import { test } from "node:test";
import assert from "node:assert/strict";
import {
  PHASE,
  GIVE_UP_MS,
  MISSED_DOWN_AFTER_MS,
  PERSIST_MAX_AGE_MS,
  PROBE_INTERVAL_MS,
  SLOW_AFTER_MS,
  SLOW_PROBE_INTERVAL_MS,
  TAKING_LONGER_MS,
  createRestartState,
  isResumable,
  isTakingLonger,
  probeIntervalMs,
  reduceProbe,
  resumeRestartState,
} from "../lib/server/restart-probe.js";

const T0 = 1_700_000_000_000;
const up = { apiUp: true, panelUp: true };
const down = { apiUp: false, panelUp: false };

function run(results, startedAt = T0) {
  let state = createRestartState(startedAt);
  let at = startedAt;
  for (const result of results) {
    at += PROBE_INTERVAL_MS;
    state = reduceProbe(state, { at, ...result });
  }
  return state;
}

test("a server that has not gone down yet is never 'back'", () => {
  // `shutdown -r now` takes seconds to bite, so the first probes answer from
  // the machine on its way out. Accepting those flashes "back online" and then
  // the panel dies under the reader.
  const state = run([up, up, up, up]);
  assert.equal(state.phase, PHASE.GOING_DOWN);
  assert.equal(state.okStreak, 0);
});

test("one success after the outage is not enough", () => {
  // A half-started nginx answers once while php-fpm is still coming up.
  const state = run([up, down, down, up]);
  assert.equal(state.phase, PHASE.COMING_BACK);
});

test("two consecutive successes after an outage means back", () => {
  const state = run([up, down, down, up, up]);
  assert.equal(state.phase, PHASE.BACK);
});

test("the API returning before the panel does is not back", () => {
  // Separate systemd units. Reloading on the API alone lands on a 502.
  const state = run([down, { apiUp: true, panelUp: false }, { apiUp: true, panelUp: false }]);
  assert.equal(state.phase, PHASE.COMING_BACK);
  assert.equal(state.okStreak, 0);
});

test("a flap resets the streak rather than counting toward recovery", () => {
  const state = run([down, up, down, up]);
  assert.equal(state.phase, PHASE.COMING_BACK);
  assert.equal(state.okStreak, 1);
});

test("terminal states stop consuming probes", () => {
  const back = run([down, up, up]);
  assert.equal(back.phase, PHASE.BACK);
  // A late probe must not drag a finished restart back into waiting.
  assert.equal(reduceProbe(back, { at: T0 + 60_000, ...down }), back);
});

test("it gives up out loud instead of spinning forever", () => {
  const state = reduceProbe(createRestartState(T0), { at: T0 + GIVE_UP_MS, ...down });
  assert.equal(state.phase, PHASE.GAVE_UP);

  const late = reduceProbe(state, { at: T0 + GIVE_UP_MS + 5000, ...up });
  assert.equal(late.phase, PHASE.GAVE_UP, "give-up is terminal");
});

test("'taking longer' changes the words, not the phase", () => {
  const waiting = reduceProbe(createRestartState(T0), { at: T0 + TAKING_LONGER_MS, ...down });
  assert.equal(waiting.phase, PHASE.OFFLINE);
  assert.equal(isTakingLonger(waiting), true);

  const early = reduceProbe(createRestartState(T0), { at: T0 + 5000, ...down });
  assert.equal(isTakingLonger(early), false);
});

test("polling backs off once the quick case is clearly not happening", () => {
  assert.equal(probeIntervalMs(0), PROBE_INTERVAL_MS);
  assert.equal(probeIntervalMs(SLOW_AFTER_MS - 1), PROBE_INTERVAL_MS);
  assert.equal(probeIntervalMs(SLOW_AFTER_MS), SLOW_PROBE_INTERVAL_MS);
});

test("resuming soon after the press still waits to see it go down", () => {
  // The machine may not have left yet; assuming otherwise would declare "back"
  // against a server that never went anywhere.
  const state = resumeRestartState(T0, T0 + 5000);
  assert.equal(state.sawDown, false);
  assert.equal(reduceProbe(state, { at: T0 + 8000, ...up }).phase, PHASE.GOING_DOWN);
});

test("resuming later accepts that the outage was missed", () => {
  const state = resumeRestartState(T0, T0 + MISSED_DOWN_AFTER_MS);
  assert.equal(state.sawDown, true);
  // Otherwise a tab opened after the reboot would wait out the full give-up
  // timer against a server that is already serving it.
  const first = reduceProbe(state, { at: T0 + MISSED_DOWN_AFTER_MS + 3000, ...up });
  const second = reduceProbe(first, { at: T0 + MISSED_DOWN_AFTER_MS + 6000, ...up });
  assert.equal(second.phase, PHASE.BACK);
});

test("a stale stored restart is not resumed", () => {
  assert.equal(isResumable(T0, T0 + 1000), true);
  assert.equal(isResumable(T0, T0 + PERSIST_MAX_AGE_MS), false);
  // Clock skew or a hand-edited value.
  assert.equal(isResumable(T0, T0 - 1000), false);
  assert.equal(isResumable(NaN, T0), false);
  assert.equal(isResumable(0, T0), false);
});

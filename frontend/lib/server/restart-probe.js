/**
 * The state machine behind the restart curtain.
 *
 * Pure on purpose: the sequencing below is the whole feature, and it is only
 * testable if it is not tangled up in an effect. The component owns timers and
 * fetches; this owns what a probe result means.
 */

export const PHASE = {
  GOING_DOWN: "going_down",
  OFFLINE: "offline",
  COMING_BACK: "coming_back",
  BACK: "back",
  GAVE_UP: "gave_up",
};

export const PROBE_INTERVAL_MS = 3000;
export const SLOW_PROBE_INTERVAL_MS = 5000;
export const SLOW_AFTER_MS = 120_000;
export const TAKING_LONGER_MS = 180_000;
export const GIVE_UP_MS = 480_000;
export const PROBE_TIMEOUT_MS = 4000;
export const PERSIST_MAX_AGE_MS = 600_000;

// Two, not one. A half-started nginx answers a single probe and then drops
// while php-fpm is still coming up; reloading on that lands on a 502.
export const REQUIRED_OK_STREAK = 2;

// Past this, a restart we are resuming has almost certainly already taken the
// machine down while no tab was watching, so requiring a fresh down would wait
// out the give-up timer against a server that is already back.
export const MISSED_DOWN_AFTER_MS = 20_000;

export function createRestartState(startedAt) {
  return {
    phase: PHASE.GOING_DOWN,
    // The reboot is not real until we have watched it fail at least once.
    sawDown: false,
    okStreak: 0,
    startedAt,
    elapsedMs: 0,
  };
}

/**
 * Rebuild state for a restart that was already running when this tab loaded.
 *
 * The transition we normally watch for happened while nothing was looking, so
 * past a short grace window we take it as already seen. Inside that window the
 * machine may not have gone down yet, and assuming otherwise would declare
 * "back" against a server that has not left.
 */
export function resumeRestartState(startedAt, now) {
  const elapsedMs = Math.max(0, now - startedAt);

  return {
    ...createRestartState(startedAt),
    elapsedMs,
    sawDown: elapsedMs >= MISSED_DOWN_AFTER_MS,
  };
}

/**
 * Fold one probe result into the state.
 *
 * `apiUp` is /api/health answering; `panelUp` is this Next server answering.
 * They are separate systemd units and the frontend regularly lags the API, so
 * "the API is back" is not "the panel is usable".
 */
export function reduceProbe(state, { at, apiUp, panelUp }) {
  if (state.phase === PHASE.BACK || state.phase === PHASE.GAVE_UP) return state;

  const elapsedMs = Math.max(0, at - state.startedAt);
  const next = { ...state, elapsedMs };

  if (elapsedMs >= GIVE_UP_MS) {
    // Announced, never silent. A spinner that has quietly stopped spinning is
    // the worst version of this screen — it still claims work is happening.
    return { ...next, phase: PHASE.GAVE_UP };
  }

  if (!apiUp) {
    return { ...next, sawDown: true, okStreak: 0, phase: PHASE.OFFLINE };
  }

  // `shutdown -r now` takes several seconds to actually kill anything, so the
  // first probes answer from the server that is on its way down. Treating that
  // as recovery flashes "back online" two seconds in and then dies.
  if (!next.sawDown) {
    return { ...next, okStreak: 0, phase: PHASE.GOING_DOWN };
  }

  if (!panelUp) {
    return { ...next, okStreak: 0, phase: PHASE.COMING_BACK };
  }

  const okStreak = next.okStreak + 1;

  return {
    ...next,
    okStreak,
    phase: okStreak >= REQUIRED_OK_STREAK ? PHASE.BACK : PHASE.COMING_BACK,
  };
}

/** Back off once the quick case is clearly not happening. */
export function probeIntervalMs(elapsedMs) {
  return elapsedMs >= SLOW_AFTER_MS ? SLOW_PROBE_INTERVAL_MS : PROBE_INTERVAL_MS;
}

/**
 * Whether to admit this is slower than advertised. Not a phase of its own —
 * it changes the words while the machine keeps doing exactly what it was.
 */
export function isTakingLonger(state) {
  return (
    state.elapsedMs >= TAKING_LONGER_MS &&
    (state.phase === PHASE.OFFLINE || state.phase === PHASE.COMING_BACK)
  );
}

/** A restart we stored and then left sitting is not one we should resume. */
export function isResumable(startedAt, now) {
  if (!Number.isFinite(startedAt) || startedAt <= 0) return false;
  const age = now - startedAt;
  return age >= 0 && age < PERSIST_MAX_AGE_MS;
}

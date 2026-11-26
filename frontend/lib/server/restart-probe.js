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

export const REQUIRED_OK_STREAK = 2;

export const MISSED_DOWN_AFTER_MS = 20_000;

export function createRestartState(startedAt) {
  return {
    phase: PHASE.GOING_DOWN,
    sawDown: false,
    okStreak: 0,
    startedAt,
    elapsedMs: 0,
  };
}

export function resumeRestartState(startedAt, now) {
  const elapsedMs = Math.max(0, now - startedAt);

  return {
    ...createRestartState(startedAt),
    elapsedMs,
    sawDown: elapsedMs >= MISSED_DOWN_AFTER_MS,
  };
}

export function reduceProbe(state, { at, apiUp, panelUp }) {
  if (state.phase === PHASE.BACK || state.phase === PHASE.GAVE_UP) return state;

  const elapsedMs = Math.max(0, at - state.startedAt);
  const next = { ...state, elapsedMs };

  if (elapsedMs >= GIVE_UP_MS) {
    return { ...next, phase: PHASE.GAVE_UP };
  }

  if (!apiUp) {
    return { ...next, sawDown: true, okStreak: 0, phase: PHASE.OFFLINE };
  }

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

export function probeIntervalMs(elapsedMs) {
  return elapsedMs >= SLOW_AFTER_MS ? SLOW_PROBE_INTERVAL_MS : PROBE_INTERVAL_MS;
}

export function isTakingLonger(state) {
  return (
    state.elapsedMs >= TAKING_LONGER_MS &&
    (state.phase === PHASE.OFFLINE || state.phase === PHASE.COMING_BACK)
  );
}

export function isResumable(startedAt, now) {
  if (!Number.isFinite(startedAt) || startedAt <= 0) return false;
  const age = now - startedAt;
  return age >= 0 && age < PERSIST_MAX_AGE_MS;
}

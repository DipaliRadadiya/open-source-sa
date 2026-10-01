/**
 * Pending-restart countdown arithmetic, kept out of the component for testing.
 * Uses the server's seconds-remaining, not `at` (no timezone offset, and
 * clocks drift): the browser only measures elapsed time.
 */

const SECOND = 1000;

/**
 * The restart moment on the browser's clock, anchored once on mount. Subtract
 * from this deadline rather than decrementing per tick: background tabs throttle timers.
 */
export function deadlineFrom(secondsRemaining, now) {
  return now + Math.max(0, secondsRemaining) * SECOND;
}

/** Seconds left until `deadline`, rounded up and never negative. */
export function remainingSeconds(deadline, now) {
  return Math.max(0, Math.ceil((deadline - now) / SECOND));
}

/**
 * Split seconds into hours/minutes/seconds. Hours are not capped at 24:
 * `shutdown -r` accepts any delay.
 */
export function splitRemaining(seconds) {
  const total = Math.max(0, Math.floor(seconds));

  return {
    hours: Math.floor(total / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

/**
 * The arithmetic behind the pending-restart countdown, kept out of the
 * component so it can be tested without a clock or a DOM.
 *
 * The server sends how many seconds are left rather than only the moment it
 * fires, because the browser's clock and the server's disagree by however far
 * they have drifted — and `at` carries no timezone offset to correct with. So
 * the browser is trusted to measure *elapsed* time, which it is good at, and
 * never to decide *what time it is*, which it is not.
 */

/** One second in milliseconds, named so the arithmetic below reads. */
const SECOND = 1000;

/**
 * The moment the restart fires, on this browser's own monotonic-enough clock.
 *
 * Anchored once when the countdown mounts. Everything after is a subtraction
 * against this deadline rather than a running decrement, which is what makes a
 * backgrounded tab correct: browsers throttle timers to once a minute or stop
 * them entirely, so a counter that subtracted one per tick would come back
 * minutes fast. A deadline cannot drift — it is just late.
 */
export function deadlineFrom(secondsRemaining, now) {
  return now + Math.max(0, secondsRemaining) * SECOND;
}

/**
 * Seconds left until `deadline`, never negative.
 *
 * Rounded up, so a countdown started at 60 shows "60" for the first moment
 * rather than flicking to 59 before the user has read it, and reaches 0 only
 * when the time is genuinely gone.
 */
export function remainingSeconds(deadline, now) {
  return Math.max(0, Math.ceil((deadline - now) / SECOND));
}

/**
 * Split a count of seconds into the parts a person reads.
 *
 * Hours are not capped at 24: `shutdown -r` accepts any delay, and a restart
 * scheduled from a shell for next week should say so rather than wrapping
 * around to a comfortable-looking small number.
 */
export function splitRemaining(seconds) {
  const total = Math.max(0, Math.floor(seconds));

  return {
    hours: Math.floor(total / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

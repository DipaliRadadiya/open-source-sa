const SECOND = 1000;

export function deadlineFrom(secondsRemaining, now) {
  return now + Math.max(0, secondsRemaining) * SECOND;
}

export function remainingSeconds(deadline, now) {
  return Math.max(0, Math.ceil((deadline - now) / SECOND));
}

export function splitRemaining(seconds) {
  const total = Math.max(0, Math.floor(seconds));

  return {
    hours: Math.floor(total / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

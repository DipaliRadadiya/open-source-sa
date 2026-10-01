// How many links are waiting on the server right now. Every in-app link
// reports into this (see components/ui/app-link.jsx) and the top bar reads it.
let count = 0;
// Bumped each time the panel goes from idle to waiting, so a reader can tell
// one wait from the next.
let generation = 0;
const listeners = new Set();
// Waits whose link unmounted mid-navigation (a closing menu or phone sidebar);
// the navigation carries on without them.
const held = new Set();
// If the page never changes (the navigation was abandoned), let go anyway.
const HOLD_LIMIT_MS = 30_000;

function emit() {
  for (const listener of listeners) listener();
}

export function beginNavigation() {
  if (count === 0) generation += 1;
  count += 1;
  emit();

  let released = false;
  return () => {
    if (released) return;
    released = true;
    count -= 1;
    emit();
  };
}

export function holdUntilPageChanges(release) {
  held.add(release);
  setTimeout(() => {
    if (held.delete(release)) release();
  }, HOLD_LIMIT_MS);
}

// For a `router.push` straight from a click, which has no Link to report it.
export function trackPush() {
  holdUntilPageChanges(beginNavigation());
}

export function pageChanged() {
  for (const release of held) release();
  held.clear();
}

export function subscribeNavigation(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

// The current wait's number, or null when nothing is waiting.
export function currentNavigation() {
  return count > 0 ? generation : null;
}

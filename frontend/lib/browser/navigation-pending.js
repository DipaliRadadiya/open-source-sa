let count = 0;
let generation = 0;
const listeners = new Set();
const held = new Set();
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

export function currentNavigation() {
  return count > 0 ? generation : null;
}

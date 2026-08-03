/**
 * Bounds an optional server-side fetch so a slow endpoint can't hold up a
 * render. Resolves to `fallback` if the promise hasn't settled in `ms`.
 *
 * Only for data the page can do without (e.g. a subtitle detail) — required
 * data should fail loudly through the error boundary instead. The underlying
 * request isn't aborted; it just stops being awaited.
 */
export function withTimeout(promise, ms, fallback = null) {
  let timer;
  return Promise.race([
    promise.finally(() => clearTimeout(timer)),
    new Promise((resolve) => {
      timer = setTimeout(() => resolve(fallback), ms);
    }),
  ]);
}

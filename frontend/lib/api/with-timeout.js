/**
 * Bounds an optional server-side fetch so a slow endpoint can't hold up a
 * render. Resolves to `fallback` if the promise hasn't settled in `ms`.
 *
 * Only for optional data; required data should fail through the error boundary.
 * The underlying request is not aborted, only no longer awaited.
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

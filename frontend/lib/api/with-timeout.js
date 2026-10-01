// For optional data only. The request is not aborted, only no longer awaited.
export function withTimeout(promise, ms, fallback = null) {
  let timer;
  return Promise.race([
    promise.finally(() => clearTimeout(timer)),
    new Promise((resolve) => {
      timer = setTimeout(() => resolve(fallback), ms);
    }),
  ]);
}

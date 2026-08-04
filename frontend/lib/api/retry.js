/**
 * Retry for the two fetches the app cannot render without: the session and the
 * permission catalog. Everything else degrades in place, so only these are
 * worth retrying.
 *
 * Retryable: a 5xx, a thrown transport error, and a **429** — the last is the
 * server saying "come back shortly", not a clear answer like other 4xx, so
 * re-asking is exactly right (a plain 4xx is left alone). 429 gets a couple of
 * short, backed-off attempts and honours `Retry-After` when it is small, so a
 * brief rate-limit burst self-heals instead of throwing the whole app to the
 * error boundary. The waits are capped so SSR never hangs on a long window.
 */
const RETRY_DELAY_MS = 250;
const RATE_LIMIT_BACKOFFS_MS = [400, 900];
const RETRY_AFTER_CAP_MS = 2000;

function retryAfterMs(res) {
  const header = res.headers?.get?.("retry-after");
  const seconds = Number(header);
  if (!Number.isFinite(seconds) || seconds <= 0) return null;
  return Math.min(seconds * 1000, RETRY_AFTER_CAP_MS);
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function fetchWithRetry(run) {
  let res;
  try {
    res = await run();
    if (res.status < 500 && res.status !== 429) return res;
  } catch {
    // Transport failure — one retry.
    await wait(RETRY_DELAY_MS);
    return run();
  }

  if (res.status !== 429) {
    // 5xx — one retry, as before.
    await wait(RETRY_DELAY_MS);
    return run();
  }

  // 429: a few short, backed-off attempts before handing the caller the 429.
  for (const backoff of RATE_LIMIT_BACKOFFS_MS) {
    await wait(retryAfterMs(res) ?? backoff);
    try {
      res = await run();
      if (res.status !== 429) return res;
    } catch {
      await wait(RETRY_DELAY_MS);
      return run();
    }
  }
  return res;
}

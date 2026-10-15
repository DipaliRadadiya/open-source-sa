// For the session and permission fetches: 5xx/transport retry once; 429 gets backed-off attempts
// honouring a small `Retry-After`, capped so SSR never hangs.
const RETRY_DELAY_MS = 250;
// Long enough to outlast an nginx reload on the same box (DS-11 saw one 10 s connect
// timeout during a deploy), short enough that a stopped API still fails promptly.
export const TRANSPORT_RETRY_DELAY_MS = 1000;
const RATE_LIMIT_BACKOFFS_MS = [400, 900];
const RETRY_AFTER_CAP_MS = 2000;

function retryAfterMs(res) {
  const header = res.headers?.get?.("retry-after");
  const seconds = Number(header);
  if (!Number.isFinite(seconds) || seconds <= 0) return null;
  return Math.min(seconds * 1000, RETRY_AFTER_CAP_MS);
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Next memoises identical GETs within a render, so a plain second fetch() gets the first
// attempt's rejection back without touching the network. A request carrying a signal is
// never memoised: `run(signal)` must pass it to fetch. The first attempt passes none, so
// identical reads in one render still share a request.
const fresh = () => new AbortController().signal;

// GET only: a request that never completed is retried once, an answer of any status is not.
export async function retryOnTransportFailure(run, delayMs = TRANSPORT_RETRY_DELAY_MS) {
  try {
    return await run();
  } catch {
    await wait(delayMs);
    return run(fresh());
  }
}

export async function fetchWithRetry(run) {
  const res = await retryOnTransportFailure(run);
  if (res.status < 500 && res.status !== 429) return res;

  if (res.status !== 429) {
    // 5xx — one retry.
    await wait(RETRY_DELAY_MS);
    return run(fresh());
  }

  // 429: a few short, backed-off attempts before handing the caller the 429.
  let last = res;
  for (const backoff of RATE_LIMIT_BACKOFFS_MS) {
    await wait(retryAfterMs(last) ?? backoff);
    try {
      last = await run(fresh());
      if (last.status !== 429) return last;
    } catch {
      await wait(RETRY_DELAY_MS);
      return run(fresh());
    }
  }
  return last;
}

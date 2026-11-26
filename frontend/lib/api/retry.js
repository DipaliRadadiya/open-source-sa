const RETRY_DELAY_MS = 250;
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

// Next memoises identical GETs in a render, so each retry needs a fresh AbortController.
const fresh = () => new AbortController().signal;

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
    await wait(RETRY_DELAY_MS);
    return run(fresh());
  }

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

// For the session and permission fetches: 5xx/transport retry once; 429 gets backed-off attempts
// honouring a small `Retry-After`, capped so SSR never hangs.
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
    // 5xx — one retry.
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

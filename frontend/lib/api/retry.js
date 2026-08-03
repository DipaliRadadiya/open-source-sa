/**
 * One retry for the two fetches the app cannot render without: the session and
 * the permission catalog. Everything else degrades in place, so only these are
 * worth a second attempt.
 *
 * Retries a 5xx or a thrown transport error exactly once, after a short pause.
 * A 4xx is the server answering clearly — asking again would just repeat it.
 */
const RETRY_DELAY_MS = 250;

export async function fetchWithRetry(run) {
  try {
    const res = await run();
    if (res.status < 500) return res;
  } catch {
    // Transport failure — fall through to the single retry.
  }

  await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY_MS));
  return run();
}

// A 503 without a sentence of the panel's own: the web server's or Laravel's bare page while
// Ubuntu's needrestart restarts nginx or PHP-FPM right after an install. The request never
// reached the panel, so one replay is safe. A panel "busy" 503 carries a message and is left alone.
const FRAMEWORK_UNAVAILABLE = /^service unavailable\.?$/i;

export const RESTART_RETRY_MS = 3000;

/** Axios config for a write that replays once after a restart 503 (see `lib/api/client.js`). */
export const RETRY_RESTART = { retryRestart: true };

export function isRestartBlip(error) {
  if (error?.response?.status !== 503) return false;
  const message = error.response.data?.message;
  const trimmed = typeof message === "string" ? message.trim() : "";
  return !trimmed || FRAMEWORK_UNAVAILABLE.test(trimmed);
}

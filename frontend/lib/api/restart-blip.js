// A bare 503 while nginx/PHP-FPM restarts after an install never reached the panel, so one retry is safe.
const FRAMEWORK_UNAVAILABLE = /^service unavailable\.?$/i;

export const RESTART_RETRY_MS = 3000;

export const RETRY_RESTART = { retryRestart: true };

export function isRestartBlip(error) {
  if (error?.response?.status !== 503) return false;
  const message = error.response.data?.message;
  const trimmed = typeof message === "string" ? message.trim() : "";
  return !trimmed || FRAMEWORK_UNAVAILABLE.test(trimmed);
}

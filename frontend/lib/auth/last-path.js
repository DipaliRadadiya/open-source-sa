import { safeNext } from "@/lib/auth/safe-next";

export const LAST_PATH_KEY = "sv:last-path";

/**
 * Best-effort: private modes and storage quotas both throw on write, and this
 * is a convenience. Failing to record where you were must never be the reason
 * a page does not render.
 */
export function rememberPath(path) {
  if (!safeNext(path)) return;
  try {
    sessionStorage.setItem(LAST_PATH_KEY, path);
  } catch {
    // Nothing to do and nothing worth saying: the fallback is "/".
  }
}

/**
 * Where to go after signing in, and forget it afterwards.
 *
 * Cleared on read so it is spent exactly once. Left in place it would outlive
 * the sign-in that used it: the next deliberate visit to /login — switching
 * accounts, say — would silently throw you at the previous person's last
 * screen instead of the landing page.
 */
export function takeRememberedPath() {
  try {
    const value = sessionStorage.getItem(LAST_PATH_KEY);
    sessionStorage.removeItem(LAST_PATH_KEY);
    return safeNext(value);
  } catch {
    return null;
  }
}

/** Signing out on purpose: there is nowhere to come back to. */
export function forgetRememberedPath() {
  try {
    sessionStorage.removeItem(LAST_PATH_KEY);
  } catch {
    // Same as the write: never a reason to fail a sign-out.
  }
}

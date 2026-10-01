import { safeNext } from "@/lib/auth/safe-next";

export const LAST_PATH_KEY = "sv:last-path";

/** Best-effort: storage writes can throw (private mode, quota). */
export function rememberPath(path) {
  if (!safeNext(path)) return;
  try {
    sessionStorage.setItem(LAST_PATH_KEY, path);
  } catch {
    // The fallback is "/".
  }
}

// Cleared on read so a later sign-in (e.g. a different account) does not reuse it.
export function takeRememberedPath() {
  try {
    const value = sessionStorage.getItem(LAST_PATH_KEY);
    sessionStorage.removeItem(LAST_PATH_KEY);
    return safeNext(value);
  } catch {
    return null;
  }
}

// Reads without clearing. Sign-out clears it, so a value here means the session expired.
export function peekRememberedPath() {
  try {
    return safeNext(sessionStorage.getItem(LAST_PATH_KEY));
  } catch {
    return null;
  }
}

/** Signing out on purpose: there is nowhere to come back to. */
export function forgetRememberedPath() {
  try {
    sessionStorage.removeItem(LAST_PATH_KEY);
  } catch {
    // Never a reason to fail a sign-out.
  }
}

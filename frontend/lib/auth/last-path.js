import { safeNext } from "@/lib/auth/safe-next";

export const LAST_PATH_KEY = "sv:last-path";

export function rememberPath(path) {
  if (!safeNext(path)) return;
  try {
    sessionStorage.setItem(LAST_PATH_KEY, path);
  } catch {
    // falls back to /
  }
}

// Cleared on read so a different account signing in later doesn't get it.
export function takeRememberedPath() {
  try {
    const value = sessionStorage.getItem(LAST_PATH_KEY);
    sessionStorage.removeItem(LAST_PATH_KEY);
    return safeNext(value);
  } catch {
    return null;
  }
}

export function peekRememberedPath() {
  try {
    return safeNext(sessionStorage.getItem(LAST_PATH_KEY));
  } catch {
    return null;
  }
}

export function forgetRememberedPath() {
  try {
    sessionStorage.removeItem(LAST_PATH_KEY);
  } catch {
    // falls back to /
  }
}

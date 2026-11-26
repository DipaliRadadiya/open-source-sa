const KEY = "sv:backup-started";

export function markBackupStarted() {
  try {
    sessionStorage.setItem(KEY, String(Date.now()));
  } catch {
    // storage blocked; ignore
  }
}

export function backupStartedWithin(windowMs) {
  if (typeof window === "undefined") return false;
  try {
    const at = Number(sessionStorage.getItem(KEY));
    return at > 0 && Date.now() - at < windowMs;
  } catch {
    return false;
  }
}

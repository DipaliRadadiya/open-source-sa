// Tells the overview that a run was just accepted from the empty state's setup
// dialog; the refresh replaces that component, so sessionStorage carries it.
const KEY = "sv:backup-started";

export function markBackupStarted() {
  try {
    sessionStorage.setItem(KEY, String(Date.now()));
  } catch {
    // Storage blocked: the overview simply will not poll on its own.
  }
}

// Read, not consumed: React may run state initialisers twice. The window ends it.
export function backupStartedWithin(windowMs) {
  if (typeof window === "undefined") return false;
  try {
    const at = Number(sessionStorage.getItem(KEY));
    return at > 0 && Date.now() - at < windowMs;
  } catch {
    return false;
  }
}

// "Back up now" in the setup dialog can be pressed on the empty state, whose
// refresh replaces it with the overview. The overview has to know a run was
// just accepted — its rows still show the previous backup — and that fact has
// no other way across the swap.
const KEY = "sv:backup-started";

export function markBackupStarted() {
  try {
    sessionStorage.setItem(KEY, String(Date.now()));
  } catch {
    // Storage blocked: the overview simply will not poll on its own.
  }
}

// Read, not consumed: it is called from a state initializer, which React may
// run twice. The window is what ends it.
export function backupStartedWithin(windowMs) {
  if (typeof window === "undefined") return false;
  try {
    const at = Number(sessionStorage.getItem(KEY));
    return at > 0 && Date.now() - at < windowMs;
  } catch {
    return false;
  }
}

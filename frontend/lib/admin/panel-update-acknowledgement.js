const STORAGE_KEY = "panel-update-acknowledged-run";

export function acknowledgePanelUpdate(storage, runId) {
  if (!storage || runId == null) return;

  try {
    storage.setItem(STORAGE_KEY, String(runId));
  } catch {
    // storage disabled; the card just comes back
  }
}

export function isPanelUpdateAcknowledged(storage, runId) {
  if (!storage || runId == null) return false;

  try {
    return storage.getItem(STORAGE_KEY) === String(runId);
  } catch {
    return false;
  }
}

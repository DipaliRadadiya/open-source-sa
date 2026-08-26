const STORAGE_KEY = "panel-update-acknowledged-run";

export function acknowledgePanelUpdate(storage, runId) {
  if (!storage || runId == null) return;

  try {
    storage.setItem(STORAGE_KEY, String(runId));
  } catch {
    // Storage can be disabled by browser privacy settings. Reload still works;
    // only persistence of the dismissed card is unavailable.
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

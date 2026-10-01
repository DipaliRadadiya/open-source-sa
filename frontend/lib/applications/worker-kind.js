// `kind` decides the graceful restart (`WorkerSupervisor::gracefulRestartCommand()`).
// `current` is always included: Server Sync can adopt any kind, and a missing option renders an empty select.
export function workerKinds(presets = [], current = null) {
  const kinds = [];
  for (const preset of presets) {
    if (preset?.kind && !kinds.includes(preset.kind)) kinds.push(preset.kind);
  }
  if (current && !kinds.includes(current)) kinds.push(current);
  return kinds;
}

// Horizon supervises its own queue workers, so the API refuses both on one site.
const MUTUALLY_EXCLUSIVE_KIND = { queue: "horizon", horizon: "queue" };

// `workers` must exclude the worker being edited.
export function conflictingKind(kind, workers = []) {
  const other = MUTUALLY_EXCLUSIVE_KIND[kind];
  if (!other) return null;
  return workers.some((worker) => worker.kind === other) ? other : null;
}

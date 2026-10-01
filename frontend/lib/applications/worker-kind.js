/**
 * A worker's `kind` decides how it restarts gracefully
 * (`WorkerSupervisor::gracefulRestartCommand()`):
 *
 *   queue    php artisan queue:restart     (finish the current job, then exit)
 *   horizon  php artisan horizon:terminate (same, through Horizon)
 *   custom   supervisord stops and starts the process
 *
 * Shared by the kind select and the template picker so they never disagree.
 */
/**
 * The kinds this site can run, from the API's presets (`WorkerPresets::for()`
 * picks by framework). `current` is always included, since Server Sync can
 * adopt a worker of any kind and a missing option renders an empty select.
 */
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

/**
 * The kind already on this site that blocks `kind`, or null. `workers` must
 * exclude the worker being edited.
 */
export function conflictingKind(kind, workers = []) {
  const other = MUTUALLY_EXCLUSIVE_KIND[kind];
  if (!other) return null;
  return workers.some((worker) => worker.kind === other) ? other : null;
}

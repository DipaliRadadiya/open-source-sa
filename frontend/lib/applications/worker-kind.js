/**
 * What a worker's kind decides, and when one kind rules out another.
 *
 * `kind` is not a label. It is the panel's answer to "how do I restart this
 * without dropping the job it is holding":
 *
 *   queue    php artisan queue:restart    — finish the current job, then exit
 *   horizon  php artisan horizon:terminate — same, through Horizon
 *   custom   no protocol; supervisord stops and starts the process
 *
 * (`WorkerSupervisor::gracefulRestartCommand()`. Craft is the exception it
 * carves out server-side — it has a queue but not Laravel's command — and the
 * panel does not need to know that to offer the choice.)
 *
 * Shared rather than kept private to the preset menu: the kind select and the
 * template picker both set the same field, and two copies of "which kinds
 * cannot coexist" is how they start disagreeing.
 */
/**
 * The kinds this site can actually run, from the API's own presets.
 *
 * `WorkerPresets::for()` picks them by framework: Laravel and Statamic get
 * queue and horizon, Craft gets queue, everything else gets custom alone. So
 * the presets already answer "which kinds suit this site" — each one carries an
 * explicit `kind` — and the frontend has no business deciding it a second time.
 *
 * It used to be a fixed `["queue","horizon","custom"]`, which mirrored what
 * SaveWorkerRequest ACCEPTS rather than what the site can use. A Node site was
 * offered Queue worker, and picking it produced a worker whose graceful restart
 * is `php artisan queue:restart` — a command that site has no way to run.
 *
 * `current` is always included even when no preset offers it: Server Sync can
 * adopt a worker whose kind the detector would not suggest, and an option
 * missing from its own select renders as an empty control. Same reasoning as
 * never disabling the value already chosen.
 */
export function workerKinds(presets = [], current = null) {
  const kinds = [];
  for (const preset of presets) {
    if (preset?.kind && !kinds.includes(preset.kind)) kinds.push(preset.kind);
  }
  if (current && !kinds.includes(current)) kinds.push(current);
  return kinds;
}

// Horizon supervises its own queue workers. Running both means every job is
// picked up twice and neither tool can see the other, so the API refuses it —
// this is the same rule, applied before the round-trip.
const MUTUALLY_EXCLUSIVE_KIND = { queue: "horizon", horizon: "queue" };

/**
 * The kind already on this site that blocks `kind`, or null.
 *
 * `workers` must already exclude the worker being edited: changing a site's
 * only queue worker into a Horizon one is allowed, and counting it against
 * itself would forbid the one edit that is always safe.
 */
export function conflictingKind(kind, workers = []) {
  const other = MUTUALLY_EXCLUSIVE_KIND[kind];
  if (!other) return null;
  return workers.some((worker) => worker.kind === other) ? other : null;
}

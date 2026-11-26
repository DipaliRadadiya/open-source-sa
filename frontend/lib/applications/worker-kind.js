export function workerKinds(presets = [], current = null) {
  const kinds = [];
  for (const preset of presets) {
    if (preset?.kind && !kinds.includes(preset.kind)) kinds.push(preset.kind);
  }
  if (current && !kinds.includes(current)) kinds.push(current);
  return kinds;
}

const MUTUALLY_EXCLUSIVE_KIND = { queue: "horizon", horizon: "queue" };

export function conflictingKind(kind, workers = []) {
  const other = MUTUALLY_EXCLUSIVE_KIND[kind];
  if (!other) return null;
  return workers.some((worker) => worker.kind === other) ? other : null;
}

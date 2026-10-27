// How long a run took, as an i18n key and its values: "3 s", "2 min 5 s", "1 h 4 min".
export function runDuration(seconds) {
  if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return null;
  const s = Math.round(seconds);
  if (s < 60) return { key: "seconds", values: { s } };
  if (s < 3600) return { key: "minutes", values: { m: Math.floor(s / 60), s: s % 60 } };
  return { key: "hours", values: { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) } };
}

/** Whether the API sends run history at all; an older one has no `running` on its rows. */
export function hasRunHistory(jobs) {
  return (jobs ?? []).some((job) => typeof job.running === "boolean");
}

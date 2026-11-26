export function runDuration(seconds) {
  if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return null;
  const s = Math.round(seconds);
  if (s < 60) return { key: "seconds", values: { s } };
  if (s < 3600) return { key: "minutes", values: { m: Math.floor(s / 60), s: s % 60 } };
  return { key: "hours", values: { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) } };
}

export function hasRunHistory(jobs) {
  return (jobs ?? []).some((job) => typeof job.running === "boolean");
}

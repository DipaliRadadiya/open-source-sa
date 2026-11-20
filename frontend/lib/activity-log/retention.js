// The oldest moment a retention of `months` keeps, as the daily cleanup reckons it
// (now minus whole months; a day the shorter month lacks falls back to its last day).
export function retentionCutoff(now, months) {
  const d = new Date(now);
  const day = d.getUTCDate();
  d.setUTCDate(1);
  d.setUTCMonth(d.getUTCMonth() - months);
  const last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
  d.setUTCDate(Math.min(day, last));
  return d;
}

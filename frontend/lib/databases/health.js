const CONNECTIONS_HIGH = 75;
const CONNECTIONS_CRITICAL = 90;

export const SLOW_SECONDS = 10;
export const STUCK_SECONDS = 60;

const SLOW_RATE_HIGH = 1;
const SLOW_RATE_CRITICAL = 10;

const RECENTLY_RESTARTED_SECONDS = 3600;

const RANK = { normal: 0, high: 1, review: 2 };

export function worstTone(tones) {
  return tones.reduce((worst, tone) => (RANK[tone] > RANK[worst] ? tone : worst), "normal");
}

export function connectionPercent(status) {
  const used = Number(status?.connections);
  const max = Number(status?.max_connections);
  if (!Number.isFinite(used) || !Number.isFinite(max) || max <= 0) return null;
  return (used / max) * 100;
}

export function connectionsTone(status) {
  const percent = connectionPercent(status);
  if (percent == null) return "normal";
  if (percent >= CONNECTIONS_CRITICAL) return "review";
  if (percent >= CONNECTIONS_HIGH) return "high";
  return "normal";
}

export function slowQueryRate(status) {
  // null = not measured (no pg_stat_statements). Don't turn it into 0.
  if (status?.slow_queries == null) return null;

  const slow = Number(status.slow_queries);
  const uptime = Number(status?.uptime_seconds);
  if (!Number.isFinite(slow) || !Number.isFinite(uptime) || uptime <= 0) return null;
  return slow / (uptime / 3600);
}

export function slowQueriesTone(status) {
  const rate = slowQueryRate(status);
  if (rate == null) return "normal";
  if (rate >= SLOW_RATE_CRITICAL) return "review";
  if (rate >= SLOW_RATE_HIGH) return "high";
  return "normal";
}

const IDLE_COMMANDS = new Set(["sleep", "idle"]);

export function isIdle(process) {
  return IDLE_COMMANDS.has((process?.command ?? "").toLowerCase());
}

// autovacuum runs as postgres, so detect workers by having no db and no query.
export function isBackgroundWorker(process) {
  return !process?.db && !process?.query;
}

export function activeQueries(processes = []) {
  return processes
    .filter((p) => !isIdle(p) && !isBackgroundWorker(p))
    .sort((a, b) => (b?.time ?? 0) - (a?.time ?? 0));
}

export function activityTone(processes = []) {
  const longest = activeQueries(processes)[0]?.time ?? 0;
  if (longest >= STUCK_SECONDS) return "review";
  if (longest >= SLOW_SECONDS) return "high";
  return "normal";
}

export function recentlyRestarted(status) {
  const uptime = Number(status?.uptime_seconds);
  return Number.isFinite(uptime) && uptime > 0 && uptime < RECENTLY_RESTARTED_SECONDS;
}

export function assessHealth({ status, processes = [] }) {
  const issues = [];

  const connections = connectionsTone(status);
  if (connections !== "normal") {
    issues.push({
      key: connections === "review" ? "connectionsCritical" : "connectionsHigh",
      tone: connections,
      percent: Math.round(connectionPercent(status) ?? 0),
    });
  }

  const active = activeQueries(processes);
  const stuck = active.filter((p) => (p?.time ?? 0) >= STUCK_SECONDS);
  const slow = active.filter(
    (p) => (p?.time ?? 0) >= SLOW_SECONDS && (p?.time ?? 0) < STUCK_SECONDS,
  );
  if (stuck.length) issues.push({ key: "stuckQueries", tone: "review", count: stuck.length });
  else if (slow.length) issues.push({ key: "slowQueries", tone: "high", count: slow.length });

  const slowRate = slowQueriesTone(status);
  if (slowRate !== "normal") {
    issues.push({
      key: slowRate === "review" ? "slowRateCritical" : "slowRateHigh",
      tone: slowRate,
      rate: Math.round(slowQueryRate(status) ?? 0),
    });
  }

  return {
    tone: worstTone(issues.map((issue) => issue.tone)),
    issues,
    recentlyRestarted: recentlyRestarted(status),
  };
}

/**
 * Turns the engine's raw counters into a verdict, using only
 * `/databases/status` and the live process list.
 */

/** Connections above this share of the ceiling are worth flagging. */
const CONNECTIONS_HIGH = 75;
const CONNECTIONS_CRITICAL = 90;

/** Seconds. Matches the thresholds the query list colours rows by. */
export const SLOW_SECONDS = 10;
export const STUCK_SECONDS = 60;

/** Slow queries per hour. Below one an hour is background noise. */
const SLOW_RATE_HIGH = 1;
const SLOW_RATE_CRITICAL = 10;

/** An engine restarted this recently explains almost any odd reading. */
const RECENTLY_RESTARTED_SECONDS = 3600;

/** normal < high < review, so the worst of a set is just a max. */
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

/** Slow queries per hour of uptime: the raw counter only ever grows. */
export function slowQueryRate(status) {
  // `null` means not measured (PostgreSQL without `pg_stat_statements`).
  // Must not become 0, which would read as healthy.
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

/* Idle connection commands: MySQL says `Sleep`, PostgreSQL says `idle`. */
const IDLE_COMMANDS = new Set(["sleep", "idle"]);

export function isIdle(process) {
  return IDLE_COMMANDS.has((process?.command ?? "").toLowerCase());
}

/**
 * Server-internal processes (e.g. PostgreSQL's checkpointer, autovacuum) that
 * `pg_stat_activity` lists beside real connections; they must never be
 * offered a "Stop query". Detected by having no database and no statement,
 * not by a missing user (autovacuum runs as `postgres`).
 */
export function isBackgroundWorker(process) {
  return !process?.db && !process?.query;
}

/** Non-idle connections, longest first — the shape the whole page reads by. */
export function activeQueries(processes = []) {
  return processes
    .filter((p) => !isIdle(p) && !isBackgroundWorker(p))
    .sort((a, b) => (b?.time ?? 0) - (a?.time ?? 0));
}

/** Rated by the longest-running active query, not the count. */
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

/**
 * The overall verdict plus its reasons. `issues` holds keys and counts, not
 * sentences; the copy lives in the message catalogue.
 */
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
    // Context, not an issue: explains small counters after a restart.
    recentlyRestarted: recentlyRestarted(status),
  };
}

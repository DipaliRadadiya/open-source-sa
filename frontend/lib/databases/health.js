/**
 * Turning the engine's raw counters into a verdict.
 *
 * The page is called "health" but only ever showed numbers — `42`, `17`, `3d`
 * — and left the reader to know which of those is bad. Every rule here is
 * derived from what `/databases/status` already returns plus the live process
 * list; nothing new is asked of the API.
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

/**
 * Slow queries is a counter that only grows, so the raw number says nothing
 * without knowing how long it has been growing. Against uptime it becomes a
 * rate, which is a thing you can actually judge.
 */
export function slowQueryRate(status) {
  // `null` means the engine does not measure this — PostgreSQL has no such
  // counter without `pg_stat_statements`, which the panel does not install.
  // `Number(null)` is 0 and 0 is finite, so without this the rate came out as
  // zero and the tone as "normal": "we never looked" rendered as good news.
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

/*
 * A connection that is holding still.
 *
 * `Sleep` is MySQL's word and was the only one here, so on PostgreSQL — which
 * says `idle` — every parked connection counted as work. The page then showed
 * "8 running" under a caption promising idle ones were counted and not listed,
 * on a server where the true number of running queries was zero.
 */
const IDLE_COMMANDS = new Set(["sleep", "idle"]);

export function isIdle(process) {
  return IDLE_COMMANDS.has((process?.command ?? "").toLowerCase());
}

/**
 * PostgreSQL's own background workers — checkpointer, walwriter, autovacuum
 * launcher and friends.
 *
 * `pg_stat_activity` lists them beside real connections, and they arrive with
 * no database and no statement: five rows of nothing, each offering a "Stop
 * query" button for a query that does not exist and a process the panel must
 * not kill. They are the server itself, not something anyone connected.
 *
 * NOT keyed on a missing user. The autovacuum launcher runs as `postgres` and
 * is every bit as internal as the anonymous ones — that assumption cost this
 * function its first version. A connection is made TO a database, so having
 * neither a database nor a statement is what actually marks one as the
 * server's own.
 *
 * Recognised by what it lacks rather than by engine, so MySQL's system threads
 * would fall out the same way if they ever appeared.
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

/**
 * How concerning the concurrent work is. A count of running threads is not
 * alarming on its own — a count of running threads where one has been going
 * for two minutes is.
 */
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
 * The whole verdict, plus the specific reasons behind it.
 *
 * `issues` is deliberately a list of keys and counts rather than sentences —
 * the copy lives in the message catalogue like every other user-facing string.
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
    // Not an issue — context. A five-minute-old engine has small counters and
    // an empty history for a reason, and saying so prevents a false alarm.
    recentlyRestarted: recentlyRestarted(status),
  };
}

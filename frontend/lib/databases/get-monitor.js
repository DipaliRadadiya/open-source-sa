import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import {
  engineStatusResponseSchema,
  dbMetricsResponseSchema,
  dbProcessesResponseSchema,
  dbTablesResponseSchema,
} from "@/lib/schemas/database";

async function read(path, schema, fallback) {
  try {
    const res = await serverFetch(path);
    if (!res.ok) return fallback;
    const parsed = schema.safeParse(await res.json());
    return parsed.success ? parsed.data : fallback;
  } catch {
    return fallback;
  }
}

/**
 * Health, history and live processes for one engine.
 *
 * Each falls back on its own rather than failing the page together: an engine
 * that answers `status` but has no history yet should still show its status.
 */
export const getEngineStatus = cache(async function getEngineStatus(engine) {
  const data = await read(
    `/databases/status/${encodeURIComponent(engine)}`,
    engineStatusResponseSchema,
    null,
  );
  return data?.status ?? null;
});

export const getDatabaseMetrics = cache(async function getDatabaseMetrics(engine) {
  const data = await read(
    `/databases/metrics/history?engine=${encodeURIComponent(engine)}`,
    dbMetricsResponseSchema,
    { metrics: [] },
  );
  return data.metrics;
});

export const getProcesses = cache(async function getProcesses(engine) {
  const data = await read(
    `/databases/processes?engine=${encodeURIComponent(engine)}`,
    dbProcessesResponseSchema,
    { processes: [] },
  );
  return data.processes;
});

/** Tables inside one database, biggest first — the reason to look is size. */
export const getTables = cache(async function getTables(databaseId) {
  const data = await read(
    `/databases/${databaseId}/tables`,
    dbTablesResponseSchema,
    { tables: [] },
  );
  return [...data.tables].sort(
    (a, b) => (b.size_bytes ?? 0) - (a.size_bytes ?? 0),
  );
});

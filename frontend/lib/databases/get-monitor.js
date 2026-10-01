import { cache } from "react";
import { read, readOr } from "@/lib/api/read";
import {
  engineStatusResponseSchema,
  dbMetricsResponseSchema,
  dbProcessesResponseSchema,
  dbTablesResponseSchema,
} from "@/lib/schemas/database";


// Each part fails independently.
export const getEngineStatus = cache(async function getEngineStatus(engine) {
  const data = await readOr(
    `/databases/status/${encodeURIComponent(engine)}`,
    engineStatusResponseSchema,
    null,
  );
  return data?.status ?? null;
});

export const getDatabaseMetrics = cache(async function getDatabaseMetrics(engine) {
  const data = await readOr(
    `/databases/metrics/history?engine=${encodeURIComponent(engine)}`,
    dbMetricsResponseSchema,
    { metrics: [] },
  );
  return data.metrics;
});

export const getProcesses = cache(async function getProcesses(engine) {
  const data = await readOr(
    `/databases/processes?engine=${encodeURIComponent(engine)}`,
    dbProcessesResponseSchema,
    { processes: [] },
  );
  return data.processes;
});

/** Tables inside one database, biggest first. */
// `failed` is kept so a failed read never shows as "No tables yet".
export const getTables = cache(async function getTables(databaseId) {
  const result = await read(`/databases/${databaseId}/tables`, dbTablesResponseSchema);
  const tables = result.failed ? [] : [...(result.data?.tables ?? [])];
  return {
    tables: tables.sort((a, b) => (b.size_bytes ?? 0) - (a.size_bytes ?? 0)),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
  };
});

import { api } from "@/lib/api/client";

export function getLiveMetrics(signal) {
  return api.get("/server/metrics/live", { signal }).then((r) => r.data?.metrics);
}

/**
 * DELETE /server/processes/{pid} — stop a process.
 *
 * TERM by default: it asks the process to shut down and lets it flush buffers
 * and close files. KILL gives it no such chance, which is exactly why it isn't
 * the default and is offered only as a second attempt.
 */
export function killProcess(pid, signal = "TERM") {
  return api.delete(`/server/processes/${encodeURIComponent(pid)}`, {
    data: { signal },
  });
}

import { api } from "@/lib/api/client";

export function getLiveMetrics(signal) {
  return api.get("/server/metrics/live", { signal }).then((r) => r.data?.metrics);
}

// TERM by default; KILL only as a second attempt.
export function killProcess(pid, signal = "TERM") {
  return api.delete(`/server/processes/${encodeURIComponent(pid)}`, {
    data: { signal },
  });
}

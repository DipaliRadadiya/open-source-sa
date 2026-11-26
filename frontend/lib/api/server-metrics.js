import { api } from "@/lib/api/client";
import { serverFactsSchema } from "@/lib/schemas/server";

export function getLiveMetrics(signal) {
  return api.get("/server/metrics/live", { signal }).then((r) => r.data?.metrics);
}

export function killProcess(pid, signal = "TERM") {
  return api.delete(`/server/processes/${encodeURIComponent(pid)}`, {
    data: { signal },
  });
}

export function getServerIdentity() {
  return api.get("/server/facts").then((r) => {
    const parsed = serverFactsSchema.safeParse(r.data?.facts);
    if (!parsed.success) return null;
    const { hostname, public_ip, ip, os } = parsed.data;
    return { hostname, ip: public_ip ?? ip, os };
  });
}

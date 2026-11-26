import { api } from "@/lib/api/client";
import { settleReloadPending } from "@/lib/applications/reload-pending";

export function runServiceAction(key, action) {
  return settleReloadPending(key, action, async () => {
    const response = await api.put(`/services/${encodeURIComponent(key)}`, { action });
    if (response?.status === 202 && response.data?.service?.scheduled) await untilBack(key);
    return response;
  });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
export const SCHEDULED_TIMING = { firstCheckMs: 1500, everyMs: 1000, giveUpMs: 20000 };

async function untilBack(key) {
  await sleep(SCHEDULED_TIMING.firstCheckMs);
  const deadline = Date.now() + SCHEDULED_TIMING.giveUpMs;
  while (Date.now() < deadline) {
    try {
      const { data } = await listServices();
      const status = data?.services?.find((service) => service.key === key)?.status;
      if (status === "active") return;
      if (status === "failed") break;
    } catch {
      // still restarting
    }
    await sleep(SCHEDULED_TIMING.everyMs);
  }
  throw new Error(`${key} did not come back after the scheduled action`);
}

export function listServices({ signal } = {}) {
  return api.get("/services", { signal });
}

export function testServiceConfig(key) {
  return api.post(`/services/${encodeURIComponent(key)}/config-test`);
}


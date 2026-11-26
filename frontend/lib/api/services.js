import { api } from "@/lib/api/client";
import { settleReloadPending } from "@/lib/applications/reload-pending";

// Responds with the refreshed service; callers re-render so the table stays consistent.
// A web server restart or reload that succeeds also clears saved-but-not-live warnings.
export function runServiceAction(key, action) {
  return settleReloadPending(key, action, async () => {
    const response = await api.put(`/services/${encodeURIComponent(key)}`, { action });
    // The panel's own web server or PHP-FPM restarts after answering (202, `scheduled`), so
    // "done" and the page's re-read wait until the unit is back.
    if (response?.status === 202 && response.data?.service?.scheduled) await untilBack(key);
    return response;
  });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
export const SCHEDULED_TIMING = { firstCheckMs: 1500, everyMs: 1000, giveUpMs: 20000 };

// Requests fail while the unit restarts; those are waited out, not reported. A unit that is
// not running by the end is reported as no answer, so the list's own re-read shows the truth.
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
      // Still restarting.
    }
    await sleep(SCHEDULED_TIMING.everyMs);
  }
  throw new Error(`${key} did not come back after the scheduled action`);
}

/** `cpu_percent` is null until a second sample exists, so CPU only appears after polling. */
export function listServices({ signal } = {}) {
  return api.get("/services", { signal });
}

/** Validate the service's configuration. Read-only; never reloads. */
export function testServiceConfig(key) {
  return api.post(`/services/${encodeURIComponent(key)}/config-test`);
}

// PHP versions and ini live in lib/api/php.js, behind the `php` permission.

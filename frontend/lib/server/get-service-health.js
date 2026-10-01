import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServices } from "@/lib/services/get-services";

/**
 * "Is anything down?" for the dashboard: returns the units that are not active.
 * Null when services cannot be read, so the caller renders nothing rather
 * than an unsupported "all running".
 */
export const getServiceHealth = cache(async function getServiceHealth() {
  const permissions = await getPermissions();
  if (!can(permissions, "service", "view")) return null;

  const { services, failed } = await getServices();
  // A failed request is not "everything is fine" — say nothing instead.
  if (failed) return null;

  const down = services.filter((service) => service.status !== "active");

  return {
    total: services.length,
    // `failed` (crashed) units lead `inactive` (stopped) ones.
    down: [...down].sort((a, b) =>
      a.status === "failed" ? -1 : b.status === "failed" ? 1 : 0,
    ),
  };
});

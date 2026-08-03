import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServices } from "@/lib/services/get-services";

/**
 * "Is anything down?", as one answer rather than a second copy of the services
 * list.
 *
 * The Services page manages units; the dashboard only needs to say whether the
 * machine is healthy. Returns the units that are NOT active, so the caller can
 * stay quiet when everything is fine and name names when it isn't.
 *
 * Null when the user cannot read services at all — the caller renders nothing,
 * rather than an empty "all running" that would be a claim we can't support.
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
    // `failed` means systemd tried and it died; `inactive` means it is simply
    // not running. Both are "not working", but a failed unit is the louder of
    // the two and should lead.
    down: [...down].sort((a, b) =>
      a.status === "failed" ? -1 : b.status === "failed" ? 1 : 0,
    ),
  };
});

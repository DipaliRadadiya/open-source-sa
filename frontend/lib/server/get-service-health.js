import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServices } from "@/lib/services/get-services";

export const getServiceHealth = cache(async function getServiceHealth() {
  const permissions = await getPermissions();
  if (!can(permissions, "service", "view")) return null;

  const { services, failed } = await getServices();
  if (failed) return null;

  const down = services.filter((service) => service.status !== "active");

  return {
    total: services.length,
    names: services.map((service) => service.label),
    down: [...down].sort((a, b) =>
      a.status === "failed" ? -1 : b.status === "failed" ? 1 : 0,
    ),
  };
});

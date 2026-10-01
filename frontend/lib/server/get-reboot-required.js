import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getSettings } from "@/lib/settings/get-settings";

// Asks whichever of two endpoints the user can read. Never throws: unreadable is `false`.
export const getRebootRequired = cache(async function getRebootRequired() {
  const permissions = await getPermissions();

  try {
    // Facts first: cheaper and more widely granted.
    if (can(permissions, "dashboard", "view")) {
      const facts = await getServerFacts();
      if (facts) return Boolean(facts.reboot_required);
    }

    if (can(permissions, "setting", "view")) {
      const { data } = await getSettings();
      return Boolean(data?.updates?.reboot_required);
    }
  } catch {
    return false;
  }

  return false;
});

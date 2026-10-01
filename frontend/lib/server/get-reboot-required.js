import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getSettings } from "@/lib/settings/get-settings";

/**
 * Whether the server needs a restart to finish applying a patch. Two endpoints
 * report it behind different permissions; this asks whichever the user can read.
 * Never throws: an unreadable answer is `false` (checked on every navigation).
 */
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

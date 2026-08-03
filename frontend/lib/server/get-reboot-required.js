import { cache } from "react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { getSettings } from "@/lib/settings/get-settings";

/**
 * Whether the server is waiting on a restart to finish applying a patch.
 *
 * Two endpoints report it, behind two different permissions, so this asks
 * whichever one the user is actually allowed to read. `cache()`d for the
 * request: the app shell asks on every page, and the dashboard and the
 * settings layout already read the same sources for their own reasons.
 *
 * Never throws and never blocks the page — an unreadable answer is `false`,
 * because a banner that appears when nothing is wrong is worse than a missing
 * one, and this is checked on every single navigation.
 */
export const getRebootRequired = cache(async function getRebootRequired() {
  const permissions = await getPermissions();

  try {
    // Facts first: it is the cheaper of the two and the more widely granted.
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

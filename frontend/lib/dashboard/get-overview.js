import { read } from "@/lib/api/read";
import { databasesResponseSchema } from "@/lib/schemas/database";
import { cronjobsResponseSchema } from "@/lib/schemas/cronjob";
import { systemUsersResponseSchema } from "@/lib/schemas/application";
import { firewallResponseSchema } from "@/lib/schemas/firewall";
import { firewallState } from "@/lib/firewall/state";

// The smallest page the API accepts (10/20/50/100; 1 is a 422): only `meta.total` is wanted.
// Null when the read failed, so a failure shows "—" and never a confident 0.
async function total(path, schema) {
  const result = await read(path, schema, { searchParams: { per_page: 10 } });
  return result.failed ? null : (result.data?.meta?.total ?? null);
}

/**
 * The dashboard's "what is on this server" strip (Joe's feedback, 5 Nov): how many of each,
 * plus whether the firewall is on. Each part only for someone who may open its page.
 */
export async function getDashboardOverview({ databases, cronjobs, systemUsers, firewall }) {
  const [databaseTotal, cronTotal, userTotal, firewallResult] = await Promise.all([
    // undefined = not this user's to see (no tile); null = could not be read ("—").
    databases ? total("/databases", databasesResponseSchema) : undefined,
    cronjobs ? total("/cronjobs", cronjobsResponseSchema) : undefined,
    systemUsers ? total("/system-users", systemUsersResponseSchema) : undefined,
    firewall ? read("/firewall", firewallResponseSchema) : null,
  ]);

  return {
    databases: databaseTotal,
    cronjobs: cronTotal,
    systemUsers: userTotal,
    // "unknown" when it could not be read: unread is not off.
    firewall: firewall
      ? firewallResult?.failed
        ? { state: "unknown", rules: null, enabled: null }
        : {
            state: firewallState(firewallResult?.data?.enabled, firewallResult?.data?.default_policy),
            enabled: firewallResult?.data?.enabled ?? null,
            rules: firewallResult?.data?.rules?.filter((rule) => rule.enabled !== false).length ?? null,
          }
      : null,
  };
}

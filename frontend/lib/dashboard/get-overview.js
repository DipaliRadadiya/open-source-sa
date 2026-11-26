import { read } from "@/lib/api/read";
import { databasesResponseSchema } from "@/lib/schemas/database";
import { cronjobsResponseSchema } from "@/lib/schemas/cronjob";
import { systemUsersResponseSchema } from "@/lib/schemas/application";
import { firewallResponseSchema } from "@/lib/schemas/firewall";
import { firewallState } from "@/lib/firewall/state";

// 1 is refused by the API, so ask for the smallest allowed page; only meta.total is used.
async function total(path, schema) {
  const result = await read(path, schema, { searchParams: { per_page: 10 } });
  return result.failed ? null : (result.data?.meta?.total ?? null);
}

export async function getDashboardOverview({ databases, cronjobs, systemUsers, firewall }) {
  const [databaseTotal, cronTotal, userTotal, firewallResult] = await Promise.all([
    databases ? total("/databases", databasesResponseSchema) : undefined,
    cronjobs ? total("/cronjobs", cronjobsResponseSchema) : undefined,
    systemUsers ? total("/system-users", systemUsersResponseSchema) : undefined,
    firewall ? read("/firewall", firewallResponseSchema) : null,
  ]);

  return {
    databases: databaseTotal,
    cronjobs: cronTotal,
    systemUsers: userTotal,
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

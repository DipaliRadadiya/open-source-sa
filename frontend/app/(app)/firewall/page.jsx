import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getFirewall, getFirewallPresets, getFirewallRules } from "@/lib/firewall/get-firewall";
import { FirewallStatusCard } from "@/components/firewall/firewall-status-card";
import { BrowserIpProvider } from "@/components/network/browser-ip";
import { RulesCard } from "@/components/firewall/rules-card";
import { UnmanagedRulesCard } from "@/components/firewall/unmanaged-rules-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("firewall");
  return { title: t("title") };
}

export default async function FirewallPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("firewall"),
  ]);

  if (!can(permissions, "firewall", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "firewall", "manage");

  const historyForEveryone = can(permissions, "activity_log", "view");

  const [
    { data, failed, status, failure, message },
    presets,
    { rules, meta, failed: rulesFailed, status: rulesStatus, failure: rulesFailure, message: rulesMessage },
  ] = await Promise.all([
    getFirewall(),
    canManage ? getFirewallPresets() : Promise.resolve([]),
    getFirewallRules(sp),
  ]);

  redirectOutOfRange("/firewall", sp, meta, rulesFailed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      {failed || !data ? (
        <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
      ) : (
        <NavTransitionProvider>
          <div className="space-y-4">
            <FirewallStatusCard
              enabled={data.enabled}
              reference={data.status_reference ?? null}
              policy={data.default_policy}
              ruleCount={rulesFailed ? data.rules.length : meta.total}
              canManage={canManage}
            />

            {rulesFailed ? (
              <LoadFailed
                description={t("rules.loadFailed")}
                status={rulesStatus}
                failure={rulesFailure} message={rulesMessage}
              />
            ) : (
              <BrowserIpProvider source="firewall">
              <RulesCard
                rules={rules}
                allRules={data.rules}
                enabled={data.enabled}
                presets={presets}
                canManage={canManage}
                historyForEveryone={historyForEveryone}
                riskyPorts={data.risky_ports}
                listening={data.listening}
                total={meta?.total ?? rules.length}
              />
              </BrowserIpProvider>
            )}

            {!rulesFailed && rules.length > 0 ? (
              <DataTablePagination meta={meta} />
            ) : null}

            <UnmanagedRulesCard rules={data.unmanaged_rules} canManage={canManage} />
          </div>
        </NavTransitionProvider>
      )}
    </div>
  );
}

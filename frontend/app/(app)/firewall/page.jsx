import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getFirewall, getFirewallPresets, getFirewallRules } from "@/lib/firewall/get-firewall";
import { FirewallStatusCard } from "@/components/firewall/firewall-status-card";
import { BrowserIpProvider } from "@/components/network/browser-ip";
import { RulesCard } from "@/components/firewall/rules-card";
import { QuickAddCard } from "@/components/firewall/quick-add-card";
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

  // Presets are fetched independently and default to an empty list, so a failure
  // cannot take the page down. `cache()`d: the layout already fetched it.
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


  // A page past the end redirects to the last real page instead of erroring.
  redirectOutOfRange("/firewall", sp, meta, rulesFailed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* A failed read must never render as "nothing is protecting this server". */}
      {failed || !data ? (
        <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
      ) : (
        <NavTransitionProvider>
          <div className="space-y-4">
            {/* Status first: every rule below is inert while the firewall is off. */}
            <FirewallStatusCard
              enabled={data.enabled}
              reference={data.status_reference ?? null}
              policy={data.default_policy}
              ruleCount={rulesFailed ? data.rules.length : meta.total}
              canManage={canManage}
            />

            {/* One-click common rules above the list; most need no parameters. */}
            {canManage ? (
              <QuickAddCard
                presets={presets}
                rules={data.rules}
                enabled={data.enabled}
                sshPort={data.ssh_port ?? null}
                riskyPorts={data.risky_ports}
                canManage={canManage}
              />
            ) : null}

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
              />
              </BrowserIpProvider>
            )}

            {!rulesFailed && rules.length > 0 ? (
              <DataTablePagination meta={meta} />
            ) : null}
          </div>
        </NavTransitionProvider>
      )}
    </div>
  );
}

import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { can } from "@/lib/permissions/can";
import { getFirewall, getFirewallPresets, getFirewallRules } from "@/lib/firewall/get-firewall";
import { FirewallStatusCard } from "@/components/firewall/firewall-status-card";
import { RulesCard } from "@/components/firewall/rules-card";
import { QuickAddCard } from "@/components/firewall/quick-add-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { PageOutOfRange } from "@/components/data-table/page-out-of-range";

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

  if (!can(permissions, "firewall", "view")) redirect("/dashboard");

  const canManage = can(permissions, "firewall", "manage");

  // Presets are only needed for the add form; a failure there must not take the
  // page down, so they're fetched independently and default to an empty list.
  // `cache()`d, so this is free here — the layout already fetched it.
  const user = await getCurrentUser();
  const isAdmin = Boolean(user?.is_admin);

  const [{ data, failed }, presets, { rules, meta, failed: rulesFailed }] = await Promise.all([
    getFirewall(),
    canManage ? getFirewallPresets() : Promise.resolve([]),
    getFirewallRules(sp),
  ]);
  const pageOutOfRange = meta.total > 0 && meta.current_page > meta.last_page;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* "We couldn't ask" must never be drawn as "nothing is protecting this
          server" — the same rule as fail2ban. */}
      {failed || !data ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <NavTransitionProvider>
          <div className="space-y-4">
            {/* Status leads, because every rule below it is inert while the
                firewall is off, and a tidy list of allow-rules reads as
                protection whether or not anything is enforcing them. */}
            <FirewallStatusCard
              enabled={data.enabled}
              policy={data.default_policy}
              ruleCount={rulesFailed ? data.rules.length : meta.total}
              canManage={canManage}
            />

            {/* One click per common rule, above the list: most rules have no
                parameters, so most of the time the form should never open. */}
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
              <LoadFailed description={t("rules.loadFailed")} />
            ) : pageOutOfRange ? (
              <PageOutOfRange lastPage={meta.last_page} />
            ) : (
              <RulesCard
                rules={rules}
                allRules={data.rules}
                enabled={data.enabled}
                presets={presets}
                canManage={canManage}
                isAdmin={isAdmin}
                yourIp={data.your_ip ?? null}
                riskyPorts={data.risky_ports}
                listening={data.listening}
              />
            )}

            {!rulesFailed && !pageOutOfRange && rules.length > 0 ? (
              <DataTablePagination meta={meta} />
            ) : null}
          </div>
        </NavTransitionProvider>
      )}
    </div>
  );
}

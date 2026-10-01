import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { CircleCheck, CircleAlert } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getFail2ban } from "@/lib/fail2ban/get-fail2ban";
import { getServerCapabilities } from "@/lib/applications/get-applications";
import { InstallPrompt } from "@/components/fail2ban/install-prompt";
import { ProtectionSection } from "@/components/fail2ban/protection-section";
import { BanRulesCard } from "@/components/fail2ban/ban-rules-card";
import { IgnoreListCard } from "@/components/fail2ban/ignore-list-card";
import { BrowserIpProvider } from "@/components/network/browser-ip";
import { Fail2banTabs } from "@/components/fail2ban/fail2ban-tabs";
import { LoadFailed } from "@/components/data-table/load-failed";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("fail2ban");
  return { title: t("title") };
}

export default async function Fail2banPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("fail2ban"),
  ]);

  if (!can(permissions, "fail2ban", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "fail2ban", "manage");
  // fail2ban has one log for all jails. Hidden without Logs permission, since
  // the link would only redirect.
  const logHref = can(permissions, "logs", "view") ? "/logs?source=fail2ban" : null;

  // The server's own address: a ban on it is refused, as it would lock out the panel.
  const [{ data, failed, status, failure, message }, { serverIp }] = await Promise.all([
    getFail2ban(),
    getServerCapabilities(),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* A failed read must never render as "no protection". */}
      {failed || !data ? (
        <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
      ) : !data.installed ? (
        <>
          {/* Polls while installing (apt can take minutes); `/fail2ban` is on the
              API's polling allowance. */}
          {data.install?.status === "installing" ? <AutoRefresh intervalMs={4000} /> : null}
          <InstallPrompt canManage={canManage} install={data.install ?? null} />
        </>
      ) : (
        <div className="space-y-4">
          {/* Bans expire and arrive on their own, so keep the page fresh. */}
          <AutoRefresh intervalMs={10000} />

          {!data.running ? <StoppedAlert t={t} /> : null}

          <BrowserIpProvider source="fail2ban">
          <Fail2banTabs
            status={data.running ? <RunningBadge data={data} t={t} /> : null}
            ignoreIps={data.settings?.ignore_ips ?? []}
            live={
              // One client component: the ban list's visibility follows the switches, so
              // they share state.
              <ProtectionSection
                jails={data.jails}
                settings={data.settings}
                banned={data.banned}
                ignoreIps={data.settings?.ignore_ips ?? []}
                canManage={canManage}
                logHref={logHref}
                serverIp={serverIp}
              />
            }
            settings={
              data.settings ? (
                // No items-start: stretching both cards to the taller one squares the columns.
                <div className="grid gap-4 lg:grid-cols-2">
                  <BanRulesCard
                    settings={data.settings}
                    presets={data.bantime_presets}
                    canManage={canManage}
                  />
                  <IgnoreListCard
                    settings={data.settings}
                    canManage={canManage}
                  />
                </div>
              ) : null
            }
          />
          </BrowserIpProvider>
        </div>
      )}
    </div>
  );
}

/**
 * Installed but stopped is the dangerous state: jails still read "enabled"
 * while nothing is watching. The service is managed on Services.
 */
function StoppedAlert({ t }) {
  return (
    <div
      role="alert"
      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3"
    >
      <p className="flex items-center gap-2 text-sm text-destructive">
        <CircleAlert className="size-4 shrink-0" />
        {t("status.stopped")}
      </p>
      <Button variant="outline" size="sm" asChild>
        <Link href="/services">{t("status.goToServices")}</Link>
      </Button>
    </div>
  );
}

function RunningBadge({ data, t }) {
  return (
    <div className="flex items-center gap-2">
      <Badge variant="success" className="gap-1.5 font-normal">
        <CircleCheck className="size-3" />
        {t("status.running")}
      </Badge>
      {data.version ? (
        <span className="text-xs text-muted-foreground">
          {t("status.version", { version: data.version })}
        </span>
      ) : null}
    </div>
  );
}

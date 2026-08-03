import Link from "next/link";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { CircleCheck, CircleAlert } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getFail2ban } from "@/lib/fail2ban/get-fail2ban";
import { InstallPrompt } from "@/components/fail2ban/install-prompt";
import { JailsCard } from "@/components/fail2ban/jails-card";
import { RecommendedSetup } from "@/components/fail2ban/recommended-setup";
import { BannedCard } from "@/components/fail2ban/banned-card";
import { BanRulesCard } from "@/components/fail2ban/ban-rules-card";
import { IgnoreListCard } from "@/components/fail2ban/ignore-list-card";
import { Fail2banTabs } from "@/components/fail2ban/fail2ban-tabs";
import { LoadFailed } from "@/components/data-table/load-failed";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

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

  if (!can(permissions, "fail2ban", "view")) redirect("/dashboard");

  const canManage = can(permissions, "fail2ban", "manage");
  // One fail2ban log for all jails, per the API docs — so this is a link to the
  // log, not to a filtered view of one ban. Hidden entirely without the Logs
  // permission: a link that lands on a redirect is worse than no link.
  const logHref = can(permissions, "logs", "view") ? "/logs?source=fail2ban" : null;

  const { data, failed } = await getFail2ban();

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* "We couldn't ask" must never render as "you have no protection". */}
      {failed || !data ? (
        <LoadFailed description={t("loadFailed")} />
      ) : !data.installed ? (
        <InstallPrompt canManage={canManage} />
      ) : (
        <div className="space-y-4">
          {/* Bans expire on their own and new ones arrive without us asking,
              so a page rendered once is wrong within the minute. */}
          <AutoRefresh intervalMs={10000} />

          {/* Stopped is an interruption and gets the full width. Running is a
              reassurance and rides beside the tabs. */}
          {!data.running ? <StoppedAlert t={t} /> : null}

          {/* Two tabs, because this page answers two unrelated questions: what
              is happening to my server right now, and what did I configure.
              Both at once was five blocks of equal weight and nowhere to look
              first. */}
          <Fail2banTabs
            status={data.running ? <RunningBadge data={data} t={t} /> : null}
            needsAttention={Boolean(
              data.your_ip && !(data.settings?.ignore_ips ?? []).includes(data.your_ip),
            )}
            live={
              <>
                {/* Above the jail list: on a fresh install this is the whole
                    task, and the list below is just the detail behind it. */}
                <RecommendedSetup
                  jails={data.jails}
                  settings={data.settings}
                  yourIp={data.your_ip}
                  ignoreIps={data.settings?.ignore_ips ?? []}
                  canManage={canManage}
                />

                <JailsCard
                  jails={data.jails}
                  settings={data.settings}
                  yourIp={data.your_ip}
                  ignoreIps={data.settings?.ignore_ips ?? []}
                  canManage={canManage}
                />
                {/* Nothing can be banned by a jail that is off, so on a fresh
                    install this card is a box explaining that a thing which
                    cannot have happened has not happened. It appears once the
                    server is actually watching — or if bans already exist. */}
                {data.jails.some((jail) => jail.enabled) || data.banned.length > 0 ? (
                  <BannedCard
                    banned={data.banned}
                    jails={data.jails}
                    canManage={canManage}
                    logHref={logHref}
                  />
                ) : null}
              </>
            }
            settings={
              data.settings ? (
                // No items-start: letting the grid stretch both cards to the
                // taller one is what actually squares the columns. Chasing
                // equal content heights never converges.
                <div className="grid gap-4 lg:grid-cols-2">
                  <BanRulesCard
                    settings={data.settings}
                    presets={data.bantime_presets}
                    canManage={canManage}
                  />
                  <IgnoreListCard
                    settings={data.settings}
                    yourIp={data.your_ip}
                    canManage={canManage}
                  />
                </div>
              ) : null
            }
          />
        </div>
      )}
    </div>
  );
}

/**
 * Installed but stopped is the dangerous state: the jails still say "enabled",
 * so the page would otherwise read as protected while nothing is watching. It
 * gets said loudly, with the way to fix it — the service lives on Services.
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

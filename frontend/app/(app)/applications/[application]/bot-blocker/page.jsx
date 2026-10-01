import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getAiBotPolicies, getBotTraffic } from "@/lib/applications/get-applications";
import { BotBlockerSection } from "@/components/applications/bot-blocker/bot-blocker-section";
import {
  BotTrafficCard,
  DEFAULT_RANGE,
  TRAFFIC_RANGES,
} from "@/components/applications/bot-blocker/bot-traffic-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.botBlocker"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationBotBlockerPage({ params, searchParams }) {
  const { application: id } = await params;
  const { days: rawDays } = await searchParams;
  // Only offered ranges; the backend would clamp any other value silently.
  const days = TRAFFIC_RANGES.includes(Number(rawDays)) ? Number(rawDays) : DEFAULT_RANGE;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.botBlocker"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  // Site deleted: land on the list and explain why via ?gone=1.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_bot_blocker", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(appPermissions, "app_bot_blocker", "manage", "application");
  const settled = isSettled(application);

  // A catalog failure is a load failure, not an empty option set.
  // Traffic needs the separate `app_log` grant; hidden without it rather than showing "no bots".
  const canSeeTraffic = can(appPermissions, "app_log", "view", "application");

  const [{ policies, failed: policiesFailed, status: policiesStatus, failure: policiesFailure, message: policiesMessage }, traffic] = settled
    ? await Promise.all([
        getAiBotPolicies(),
        canSeeTraffic ? getBotTraffic(id, days) : Promise.resolve(null),
      ])
    : [{ policies: null, failed: false }, null];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : policiesFailed || !policies ? (
        <LoadFailed description={t("loadFailed")} status={policiesStatus} failure={policiesFailure} message={policiesMessage} />
      ) : (
        <>
          <BotBlockerSection
            appId={id}
            policies={policies}
            currentPolicy={application.ai_bot_policy ?? "allow_all"}
            currentBlocked={application.bot_blocked ?? []}
            currentAllowed={application.bot_allowed ?? []}
            canManage={canManage}
          />
          {/* Below the choices: often empty, must not push the main control off screen. */}
          {canSeeTraffic ? (
            <BotTrafficCard
              appId={id}
              traffic={traffic?.traffic ?? null}
              failed={traffic?.failed ?? true}
              days={days}
            />
          ) : null}
        </>
      )}
    </div>
  );
}

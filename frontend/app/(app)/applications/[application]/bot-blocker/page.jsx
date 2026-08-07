import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
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
  // Only the ranges the card offers — a hand-typed ?days=365 must not become a
  // request the backend then clamps to something the UI never said.
  const days = TRAFFIC_RANGES.includes(Number(rawDays)) ? Number(rawDays) : DEFAULT_RANGE;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.botBlocker"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  if (!can(appPermissions, "app_bot_blocker", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(appPermissions, "app_bot_blocker", "manage", "application");
  const settled = application.status === "active";

  // The whole screen is rendered from this catalog — without it there is
  // nothing truthful to show, so a failure here is a load failure, not an
  // empty set of options.
  // The traffic panel reads the site's access log, so it is gated by `app_log`
  // — a separate grant from the bot blocker's own. Without it the panel is not
  // rendered at all rather than shown empty, which would misreport "no bots".
  const canSeeTraffic = can(appPermissions, "app_log", "view", "application");

  const [{ policies, failed: policiesFailed }, traffic] = settled
    ? await Promise.all([
        getAiBotPolicies(),
        canSeeTraffic ? getBotTraffic(id, days) : Promise.resolve(null),
      ])
    : [{ policies: null, failed: false }, null];

  return (
    <div className="space-y-6">
      <PageHeader
        backHref="/applications"
        backLabel={t("back")}
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : policiesFailed || !policies ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <>
          <BotBlockerSection
            appId={id}
            policies={policies}
            currentPolicy={application.ai_bot_policy ?? "allow_all"}
            canManage={canManage}
          />
          {/* Below the choices, not above: on most sites this is empty or the
              log cannot be read, and an empty panel must not push the control
              this page exists for off the screen. */}
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

import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getCronjobs } from "@/lib/cronjobs/get-cronjobs";
import {
  getSchedulePresets,
  getCommandPresets,
} from "@/lib/cronjobs/get-cron-presets";
import { getSystemUserOptions } from "@/lib/system-users/get-system-users";
import { getAllApplications } from "@/lib/applications/get-applications";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { withTimeout } from "@/lib/api/with-timeout";
import { CronjobsPanel } from "@/components/cronjobs/cronjobs-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("cronJobs");
  return { title: t("title") };
}

export default async function CronjobsPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("cronJobs"),
  ]);

  if (!can(permissions, "cronjob", "view")) redirect("/dashboard");

  const canManage = can(permissions, "cronjob", "manage");
  const [{ cronjobs, meta, failed }, runAs, schedulePresets, commandPresets, facts, sites] =
    await Promise.all([
      getCronjobs(sp),
      // Not gated on `canManage`: the "Runs as" FILTER is part of the toolbar,
      // which every viewer sees. Tied to manage, a view-only role got a filter
      // offering only the usernames that happened to be on the current page —
      // no panel-managed account at all — which is not the job that filter has.
      //
      // `failed` is kept rather than flattened away: this endpoint needs the
      // `system_user` permission, which is unrelated to `cronjob`, so a 403 is
      // ordinary here. Without the flag an empty picker is indistinguishable
      // from "this server has no system users".
      getSystemUserOptions(),
      getSchedulePresets(),
      canManage ? getCommandPresets() : Promise.resolve({ presets: [] }),
      // Only for the timezone: a schedule is meaningless without knowing which
      // clock it runs on, and users assume their own. /server/facts shells out
      // on the backend, so it's bounded — a nice-to-have subtitle must never
      // hold up the page, and losing it costs nothing.
      withTimeout(getServerFacts(), 2000),
      // For the command form's path picker. A failure here costs the
      // convenience, not the form — it falls back to typing a path.
      canManage ? getAllApplications() : Promise.resolve({ applications: [] }),
    ]);

  const timezone = facts?.timezone;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">
          {timezone ? t("subtitleWithZone", { timezone }) : t("subtitle")}
        </p>
      </div>

      {/* The list failed, so we can't say what jobs exist — but the heading and
          the shell are still true. Only the list says it's broken. */}
      {failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <NavTransitionProvider>
          <CronjobsPanel
            cronjobs={cronjobs}
            meta={meta}
            systemUsers={runAs.users}
            systemUsersFailed={runAs.failed}
            applications={sites.applications}
            canManage={canManage}
            schedulePresets={schedulePresets}
            commandPresets={commandPresets.presets}
            placeholder={commandPresets.placeholder}
            timezone={timezone}
            isFiltered={Boolean(sp.user || sp.active)}
          />
        </NavTransitionProvider>
      )}
    </div>
  );
}

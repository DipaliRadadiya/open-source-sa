import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getCronjobs } from "@/lib/cron-jobs/get-cronjobs";
import {
  getSchedulePresets,
  getCommandPresets,
} from "@/lib/cron-jobs/get-cron-presets";
import { getSystemUserOptions } from "@/lib/system-users/get-system-users";
import { getAllApplications } from "@/lib/applications/get-applications";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { withTimeout } from "@/lib/api/with-timeout";
import { CronjobsPanel } from "@/components/cron-jobs/cronjobs-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

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

  if (!can(permissions, "cronjob", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "cronjob", "manage");
  // Output lives in the Logs registry, gated on `logs`, not `cronjob`.
  const canViewLogs = can(permissions, "logs", "view");
  const [{ cronjobs, meta, failed, status, failure, message }, runAs, schedulePresets, commandPresets, facts, sites] =
    await Promise.all([
      getCronjobs(sp),
      // Not gated on `canManage`: every viewer sees the "Runs as" filter.
      // `failed` is kept: this needs the unrelated `system_user` permission,
      // so a 403 is ordinary and must not read as "no system users".
      getSystemUserOptions(),
      getSchedulePresets(),
      canManage ? getCommandPresets() : Promise.resolve({ presets: [] }),
      // Only for the timezone. /server/facts shells out on the backend, so it
      // is bounded and must never hold up the page.
      withTimeout(getServerFacts(), 2000),
      // For the command form's path picker; on failure the user types a path.
      canManage ? getAllApplications() : Promise.resolve({ applications: [] }),
    ]);

  const timezone = facts?.timezone;


  redirectOutOfRange("/cron-jobs", sp, meta, failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {failed ? (
        <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
      ) : (
        <NavTransitionProvider>
          <CronjobsPanel
            cronjobs={cronjobs}
            meta={meta}
            systemUsers={runAs.users}
            systemUsersFailed={runAs.failed}
            applications={sites.applications}
            canManage={canManage}
            canViewLogs={canViewLogs}
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

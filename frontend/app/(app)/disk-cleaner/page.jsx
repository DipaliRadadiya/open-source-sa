import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import {
  getDiskCleaner,
  getCleanerSchedule,
  getCleanerRuns,
} from "@/lib/disk-cleaner/get-disk-cleaner";
import { DiskSummary } from "@/components/disk-cleaner/disk-summary";
import { CleanupPanel } from "@/components/disk-cleaner/cleanup-panel";
import { ScheduleCard } from "@/components/disk-cleaner/schedule-card";
import { RunsCard } from "@/components/disk-cleaner/runs-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { hasKeepChoice, keepOptions } from "@/lib/disk-cleaner/keep-days";
import { KeepProvider } from "@/components/disk-cleaner/keep-context";
import { perPageFrom } from "@/lib/tables/remembered-per-page";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("diskCleaner");
  return { title: t("title") };
}

export default async function DiskCleanerPage({ searchParams }) {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("diskCleaner"),
  ]);

  if (!can(permissions, "disk_cleaner", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "disk_cleaner", "manage");

  const query = await searchParams;
  const runsPage = Math.max(1, Number.parseInt(query?.page, 10) || 1);
  const perPage = await perPageFrom(query?.per_page);

  const [{ data, failed, status, failure, message }, schedule, { runs, meta }] = await Promise.all([
    getDiskCleaner(),
    getCleanerSchedule(),
    getCleanerRuns(runsPage, perPage),
  ]);

  if (failed || !data) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const categories = data.categories ?? [];
  const { recommended } = keepOptions(data.keep_days);
  const keepSupported = hasKeepChoice(data.keep_days);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      <KeepProvider initial={keepSupported ? recommended : null}>
        <div className="space-y-4">
          <div className="@container/tiles">
            <div className="grid gap-3 sm:gap-4 @min-[52rem]/tiles:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1.3fr)]">
              <DiskSummary disk={data.disk} categories={categories} />
              <ScheduleCard schedule={schedule} categories={categories} keepDays={data.keep_days} canManage={canManage} />
            </div>
          </div>

          <CleanupPanel
            categories={categories}
            keepDays={data.keep_days}
            canManage={canManage}
            measuredAt={new Date().toISOString()}
          />

          <RunsCard runs={runs} meta={meta} categories={categories} keepSupported={keepSupported} />
        </div>
      </KeepProvider>
    </div>
  );
}

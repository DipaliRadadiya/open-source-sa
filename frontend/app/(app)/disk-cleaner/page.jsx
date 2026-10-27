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

  // Schedule and history are secondary: on failure they degrade to null/empty
  // rather than taking the page down.
  // History pages through the URL like every other list; the backend allows 10/20/50/100.
  const query = await searchParams;
  const runsPage = Math.max(1, Number.parseInt(query?.page, 10) || 1);
  const perPage = [10, 20, 50, 100].includes(Number(query?.per_page)) ? Number(query.per_page) : 10;

  const [{ data, failed, status, failure, message }, schedule, { runs, meta }] = await Promise.all([
    getDiskCleaner(),
    getCleanerSchedule(),
    getCleanerRuns(runsPage, perPage),
  ]);

  if (failed || !data) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const categories = data.categories ?? [];
  // The list opens on the recommended keep; the "Can be freed" tile follows whatever is chosen.
  const { recommended } = keepOptions(data.keep_days);
  // An older API has no choice: null keeps every size, request and label as before.
  const keepSupported = hasKeepChoice(data.keep_days);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* Three compact tiles across the top (one per row until the page is wide enough);
          the schedule tile gets the most room, "Can be freed" the least (a short number).
          The list gets full width below. */}
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

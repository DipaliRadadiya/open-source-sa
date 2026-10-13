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
  const cleanable = categories.filter((category) => category.available && category.reclaimable > 0);
  const reclaimable = cleanable.reduce((total, category) => total + category.reclaimable, 0);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* Three compact tiles across the top (one per row until the page is wide enough);
          the schedule tile gets the most room, "Can be freed" the least (a short number).
          The list gets full width below. */}
      <div className="space-y-4">
        <div className="@container/tiles">
          <div className="grid gap-3 sm:gap-4 @min-[52rem]/tiles:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1.3fr)]">
            <DiskSummary
              disk={data.disk}
              reclaimableHuman={humanBytes(reclaimable)}
              reclaimableCount={cleanable.length}
            />
            <ScheduleCard schedule={schedule} categories={categories} canManage={canManage} />
          </div>
        </div>

        <CleanupPanel
          categories={categories}
          canManage={canManage}
          measuredAt={new Date().toISOString()}
        />

        <RunsCard runs={runs} meta={meta} categories={categories} />
      </div>
    </div>
  );
}

// Only this page-level total is formatted locally: the API never sends the sum.
function humanBytes(bytes) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}

import { redirect } from "next/navigation";
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

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("diskCleaner");
  return { title: t("title") };
}

export default async function DiskCleanerPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("diskCleaner"),
  ]);

  if (!can(permissions, "disk_cleaner", "view")) redirect("/dashboard");
  const canManage = can(permissions, "disk_cleaner", "manage");

  // The schedule and history are secondary: if either fails the page is still
  // useful, so they degrade to null/empty rather than taking the page down.
  const [{ data, failed }, schedule, { runs }] = await Promise.all([
    getDiskCleaner(),
    getCleanerSchedule(),
    getCleanerRuns(),
  ]);

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  const categories = data.categories ?? [];
  const reclaimable = categories
    .filter((category) => category.available)
    .reduce((total, category) => total + (category.reclaimable ?? 0), 0);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* Status across the top, the thing you act on underneath.
          A side column held only two short cards against a long list, so the
          page ended in a tall empty strip; side by side they fill one band and
          the list gets the full width it actually needs. */}
      <div className="max-w-5xl space-y-4">
        <div className="grid gap-4 md:grid-cols-2">
          <DiskSummary
            disk={data.disk}
            reclaimableHuman={humanBytes(reclaimable)}
          />
          <ScheduleCard schedule={schedule} categories={categories} canManage={canManage} />
        </div>

        <CleanupPanel
          categories={categories}
          canManage={canManage}
          measuredAt={new Date().toISOString()}
        />

        <RunsCard runs={runs} />
      </div>
    </div>
  );
}

// The API formats every per-category size; only this page-level total is ours
// to format, because it is a sum the API never sends.
function humanBytes(bytes) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}

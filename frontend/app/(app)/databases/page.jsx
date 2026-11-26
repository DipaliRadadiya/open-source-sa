import { redirect } from "next/navigation";
import { getTranslations, getFormatter } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import {
  getEngines,
  getDatabases,
  getUntracked,
  getConnections,
} from "@/lib/databases/get-databases";
import { getExports } from "@/lib/databases/get-exports";
import {
  getAllApplications,
  getPhpmyadminSite,
  getAdminerSite,
  getSiteTypes,
} from "@/lib/applications/get-applications";
import {
  getDatabaseCounts,
  getUnlinkedCount,
} from "@/lib/databases/get-databases";
import { formatBytes } from "@/lib/format/bytes";
import { parseApiDate } from "@/lib/format/api-date";
import { EngineBar } from "@/components/databases/engine-bar";
import { EngineState } from "@/components/databases/engine-state";
import { UntrackedBanner } from "@/components/databases/untracked-banner";
import { UnlinkedBanner } from "@/components/databases/unlinked-banner";
import { DatabasesTable } from "@/components/databases/databases-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { Database } from "lucide-react";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("databases");
  return { title: t("title") };
}

export default async function DatabasesPage({ searchParams }) {
  const sp = await searchParams;
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();
  const [permissions, t, format, live] = await Promise.all([
    getPermissions(),
    getTranslations("databases"),
    getFormatter(),
    getEngines(),
  ]);
  const { engines, failed, status, failure, message } = live;

  if (!can(permissions, "database", "view"))
    return <PermissionDenied title={t("title")} feature="database" />;
  const canManage = can(permissions, "database", "manage");

  if (failed && status === 409) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("title")} />
        <EmptyState
          icon={Database}
          title={t("unavailable.title")}
          description={t("unavailable.body")}
        />
      </div>
    );
  }

  if (failed)
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={status}
        failure={failure}
        message={message}
      />
    );

  const usable = engines.some((engine) => engine.running);

  const [
    {
      databases,
      meta: dbMeta,
      failed: dbFailed,
      status: dbStatus,
      failure: dbFailure,
      message: dbMessage,
    },
    untracked,
    connections,
    exportList,
    phpmyadmin,
    adminer,
    appList,
    dbCounts,
    unlinkedCount,
    catalogue,
  ] = await Promise.all([
    usable
      ? getDatabases(query)
      : Promise.resolve({ databases: [], failed: false }),
    usable && canManage ? getUntracked(engines) : Promise.resolve([]),
    canManage ? getConnections() : Promise.resolve([]),
    usable ? getExports() : Promise.resolve({ exports: [], failed: false }),
    usable ? getPhpmyadminSite() : Promise.resolve(null),
    usable ? getAdminerSite() : Promise.resolve(null),
    usable && canManage ? getAllApplications() : Promise.resolve({ applications: [] }),
    usable && canManage ? getDatabaseCounts() : Promise.resolve({ counts: null, known: false }),
    usable ? getUnlinkedCount() : Promise.resolve(0),
    usable && canManage
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
  ]);

  if (dbFailed && dbStatus === 422 && ["sort", "engine", "attached", "per_page", "direction"].some((key) => sp?.[key])) {
    const kept = new URLSearchParams();
    if (typeof sp.search === "string" && sp.search) kept.set("search", sp.search);
    redirect(`/databases${kept.size ? `?${kept}` : ""}`);
  }

  if (dbFailed)
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={dbStatus}
        failure={dbFailure}
        message={dbMessage}
      />
    );

  const lastBackup = {};
  for (const row of exportList.exports) {
    if (row.status !== "completed" || row.available === false) continue;
    const at = parseApiDate(row.finished_at ?? row.created_at)?.getTime() ?? 0;
    const current = lastBackup[row.database_id];
    if (!current || at > current.at)
      lastBackup[row.database_id] = { ...row, at };
  }

  const totalBytes = databases.reduce(
    (sum, db) => sum + (Number(db.size_bytes) || 0),
    0,
  );
  const wholeList = databases.length === (dbMeta?.total ?? databases.length);
  const summary = databases.length
    ? [
        t("summary.count", { count: dbMeta?.total ?? databases.length }),
        wholeList ? formatBytes(totalBytes, format) : null,
      ]
        .filter(Boolean)
        .join(" · ")
    : null;

  redirectOutOfRange("/databases", sp, dbMeta, dbFailed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      {usable ? (
        <div className="space-y-4">
          <EngineBar
            engines={engines}
            connections={connections}
            canManage={canManage}
            summary={summary}
          />
          <UntrackedBanner untracked={untracked} canManage={canManage} />
          <UnlinkedBanner
            count={unlinkedCount}
            filtered={new URLSearchParams(query).get("attached") === "0"}
          />
          <DatabasesTable
            data={databases}
            meta={dbMeta}
            engines={engines}
            canManage={canManage}
            lastBackup={lastBackup}
            backupsUnknown={exportList.failed}
            phpmyadminSites={phpmyadmin.known ? phpmyadmin.sites : null}
            adminerSites={adminer?.known ? adminer.sites : null}
            applications={appList.applications}
            canOpenApplications={can(permissions, "application", "view")}
            databaseCounts={dbCounts.counts}
            databasesKnown={dbCounts.known}
            siteTypes={catalogue.siteTypes}
          />
        </div>
      ) : (
        <EngineState
          engines={engines}
          connections={connections}
          canManage={canManage}
        />
      )}
    </div>
  );
}

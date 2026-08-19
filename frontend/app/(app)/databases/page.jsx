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
import { formatBytes } from "@/lib/format/bytes";
import { parseApiDate } from "@/lib/format/api-date";
import { EngineBar } from "@/components/databases/engine-bar";
import { EngineState } from "@/components/databases/engine-state";
import { UntrackedBanner } from "@/components/databases/untracked-banner";
import { DatabasesTable } from "@/components/databases/databases-table";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("databases");
  return { title: t("title") };
}

export default async function DatabasesPage({ searchParams }) {
  const sp = await searchParams;
  // Serialised so React's `cache` sees a stable primitive argument.
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();
  const [permissions, t, format, live] = await Promise.all([
    getPermissions(),
    getTranslations("databases"),
    getFormatter(),
    getEngines(),
  ]);
  const { engines, failed } = live;

  if (!can(permissions, "database", "view")) redirect("/dashboard");
  const canManage = can(permissions, "database", "manage");

  if (failed) return <LoadFailed description={t("loadFailed")} />;

  // Only a REACHABLE engine can hold databases. An install that is queued or
  // failed has none, so asking for a list would spend a request to render a
  // table that then invites you to create a database nothing could store.
  const usable = engines.some((engine) => engine.running);

  const [{ databases, meta: dbMeta }, untracked, connections, exportList] = await Promise.all([
    usable ? getDatabases(query) : Promise.resolve({ databases: [] }),
    usable && canManage ? getUntracked(engines) : Promise.resolve([]),
    // Needed most when nothing is reachable — that is when someone has to look
    // at these settings.
    canManage ? getConnections() : Promise.resolve([]),
    // For the "Last backup" column. Global, so one request covers the whole
    // table rather than one per row.
    usable ? getExports() : Promise.resolve({ exports: [], failed: false }),
  ]);

  // The newest dump per database that you could actually restore from:
  // finished, and its file still on disk. A completed export whose file was
  // removed by hand is not protection, and showing its date as the last backup
  // is the one answer worse than saying "Never".
  //
  // Compared on the timestamp rather than trusting the endpoint's ordering or
  // that ids ascend with time.
  const lastBackup = {};
  for (const row of exportList.exports) {
    if (row.status !== "completed" || row.available === false) continue;
    const at = parseApiDate(row.finished_at ?? row.created_at)?.getTime() ?? 0;
    const current = lastBackup[row.database_id];
    if (!current || at > current.at) lastBackup[row.database_id] = { ...row, at };
  }

  // "4 databases · 212 MB" beside the engine, rather than two stat tiles for
  // numbers nobody makes a decision from.
  const totalBytes = databases.reduce(
    (sum, db) => sum + (Number(db.size_bytes) || 0),
    0,
  );
  const summary = databases.length
    ? [
        t("summary.count", { count: databases.length }),
        formatBytes(totalBytes, format),
      ]
        .filter(Boolean)
        .join(" · ")
    : null;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {usable ? (
        <div className="space-y-4">
          <EngineBar
            engines={engines}
            connections={connections}
            canManage={canManage}
            summary={summary}
          />
          <UntrackedBanner untracked={untracked} canManage={canManage} />
          <DatabasesTable
            data={databases}
            meta={dbMeta}
            engines={engines}
            canManage={canManage}
            lastBackup={lastBackup}
            // A failed exports request must not read as "never backed up" —
            // that is the one wrong answer this column can give.
            backupsUnknown={exportList.failed}
          />
        </div>
      ) : (
        // Nothing to connect to: installing, failed, or never installed. The
        // engine's state is the page.
        <EngineState
          engines={engines}
          connections={connections}
          canManage={canManage}
        />
      )}
    </div>
  );
}

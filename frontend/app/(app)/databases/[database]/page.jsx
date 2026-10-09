import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getDatabase } from "@/lib/databases/get-database";
import { getExports } from "@/lib/databases/get-exports";
import { getDatabaseCounts, getEngines } from "@/lib/databases/get-databases";
import { supportsRemoteUsers } from "@/lib/databases/engine-capabilities";
import {
  getAllApplications,
  getPhpmyadminSite,
  getSiteTypes,
} from "@/lib/applications/get-applications";
import { getTables } from "@/lib/databases/get-monitor";
import { Badge } from "@/components/ui/badge";
import { DatabaseUsers } from "@/components/databases/database-users";
import { ConnectionDetails } from "@/components/databases/connection-details";
import { DatabaseTabs } from "@/components/databases/database-tabs";
import { DatabaseDetailsCard } from "@/components/databases/database-details-card";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";
import { parseApiDate } from "@/lib/format/api-date";
import { DatabaseTables } from "@/components/databases/database-tables";
import { DatabaseExports } from "@/components/databases/database-exports";
import { applicationById } from "@/lib/backups/database-availability";
import { DeleteDatabaseCard } from "@/components/databases/delete-database-card";
import { PageCrumb } from "@/components/sections/page-crumb";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import Link from "@/components/ui/app-link";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { DetailHeader } from "@/components/ui/detail-header";
import { BackLink } from "@/components/ui/back-link";
import { Database } from "lucide-react";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { database } = await params;
  const { data } = await getDatabase(database);
  return { title: data?.name ?? "" };
}

export default async function DatabasePage({ params, searchParams }) {
  const { database: id } = await params;
  const sp = await searchParams;

  const [
    permissions,
    t,
    live,
    exportList,
    tables,
    phpmyadmin,
    appList,
    dbCounts,
    catalogue,
    engines,
  ] = await Promise.all([
    getPermissions(),
    getTranslations("databases"),
    getDatabase(id),
    // Exports are global (they outlive their database); the card filters them.
    getExports(),
    getTables(id),
    getPhpmyadminSite(),
    // `DatabaseResource` has only `application_id`, so sites are joined here.
    // The counts drive the picker's "already has one".
    getAllApplications(),
    getDatabaseCounts(),
    // Lets the site picker grey out sites that cannot use this engine.
    // A failure only loses the greying.
    getSiteTypes().catch(() => ({ siteTypes: [] })),
    // A failure only loses the narrowing. Returns `{ engines, failed }`, NOT an array.
    getEngines().catch(() => ({ engines: [] })),
  ]);
  const { data, failed, status, failure, message } = live;

  if (!can(permissions, "database", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "database", "manage");

  // Deleted elsewhere: show the 404 page, not a load error.
  if (status === 404) notFound();
  if (failed || !data)
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={status}
        failure={failure} message={message}
      />
    );

  // The API answers 200 with no tables and size 0 when the engine is down,
  // so the engine's own state is the reliable signal.
  const engineRow = (engines.engines ?? []).find((row) => row.engine === data.engine);
  const engineDown = Boolean(engineRow?.installed) && !engineRow.running;
  const engineName = t(`engines.${data.engine}`);

  // The newest restorable dump: completed and its file still on disk, as on the list.
  const ownExports = exportList.exports.filter((row) => row.database_id === data.id);
  const lastExport = exportList.failed
    ? undefined
    : (ownExports
        .filter((row) => row.status === "completed" && row.available !== false)
        .map((row) => ({ row, at: parseApiDate(row.finished_at ?? row.created_at)?.getTime() ?? 0 }))
        .sort((a, b) => b.at - a.at)[0]?.row ?? null);

  return (
    <div className="space-y-6">
      <PageCrumb mono>{data.name}</PageCrumb>

      {/* As wide as the cards below it. */}
      <div className="space-y-3">
        {/* Asked for on 1 Oct, alongside the breadcrumb. */}
        <BackLink href="/databases">{t("backToList")}</BackLink>
        <DetailHeader
          icon={<Database aria-hidden />}
          title={data.name}
          mono
          badges={<Badge variant="muted">{engineName}</Badge>}
          facts={[
            // The API reports 0 B while the engine is down, which would read as empty.
            engineDown ? null : <span className="font-mono">{data.size_human}</span>,
            data.created_at_human ? t("detail.createdWhen", { when: data.created_at_human }) : null,
          ]}
          // The one thing people open a database page to do, so it is the filled button.
          actions={
            <PhpmyadminButton
              database={data}
              canManage={canManage}
              sites={phpmyadmin.known ? phpmyadmin.sites : null}
              variant="default"
              size="default"
            />
          }
        />
      </div>

      <div className="space-y-4">
        {engineDown ? (
          <Caution
            action={
              <Button asChild variant="outline" size="sm">
                <Link href="/services">{t("status.checkServices")}</Link>
              </Button>
            }
          >
            {t("detail.engineDown", { engine: engineName })}
          </Caution>
        ) : null}

        <DatabaseTabs
          initial={sp?.tab}
          overview={
            <div className="space-y-4">
              <ConnectionDetails database={data} canManage={canManage} />
              <DatabaseDetailsCard
                database={data}
                engineName={engineName}
                // "10.11.14", not the build string "10.11.14-MariaDB-0ubuntu0.24.04.1".
                engineVersion={engineRow?.version?.match(/^\d+(?:\.\d+)*/)?.[0] ?? engineRow?.version ?? null}
                engineDown={engineDown}
                lastExport={lastExport === undefined ? undefined : (lastExport?.finished_at_human ?? lastExport?.created_at_human ?? null)}
                canManage={canManage}
                applications={appList.applications}
                databaseCounts={dbCounts.counts}
                databasesKnown={dbCounts.known}
                siteTypes={catalogue.siteTypes}
              />
              {/* In the Overview, not under every tab: it is the page's last word, not a footer. */}
              <DeleteDatabaseCard
                database={data}
                application={applicationById(appList.applications, data.application_id)}
                canManage={canManage}
              />
            </div>
          }
          counts={{
            users: data.users?.length ?? 0,
            // Null, not 0, when the list could not be read.
            tables: tables.failed || engineDown ? null : tables.tables.length,
            exports: exportList.failed ? null : ownExports.length,
          }}
          users={
            <DatabaseUsers
              database={data}
              canManage={canManage}
              /* PostgreSQL roles carry no host, so `remote` and `anywhere` are
                 refused by the API. Read from the engine row, never the name. */
              remoteUsers={supportsRemoteUsers(engines, data.engine)}
            />
          }
          tables={
            <DatabaseTables
              database={data}
              tables={tables.tables}
              read={tables}
              unavailable={engineDown ? engineName : null}
            />
          }
          exports={
            <DatabaseExports
              database={data}
              exports={exportList.exports}
              read={exportList}
              canManage={canManage}
            />
          }
        />
      </div>
    </div>
  );
}

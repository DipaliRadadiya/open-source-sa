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
import { DatabaseFacts } from "@/components/databases/database-facts";
import { ConnectionDetails } from "@/components/databases/connection-details";
import { DatabaseTabs } from "@/components/databases/database-tabs";
import { UsedByCard } from "@/components/databases/used-by-card";
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
import { BackLink } from "@/components/ui/back-link";

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
    /*
     * For `supports_remote_users`; a failure only loses the narrowing.
     * Returns `{ engines, failed }`, NOT an array.
     */
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

  return (
    <div className="space-y-6">
      <PageCrumb mono>{data.name}</PageCrumb>

      <div className="space-y-3">
        <BackLink href="/databases">{t("backToList")}</BackLink>
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="min-w-0 font-mono text-2xl font-semibold tracking-tight break-words">
              {data.name}
            </h1>
            <Badge variant="secondary" className="font-normal">
              {t(`engines.${data.engine}`)}
            </Badge>
          </div>
          <DatabaseFacts database={data} hideSize={engineDown} />
        </div>
      </div>

      <div className="max-w-4xl space-y-4">
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

        {/* Above the tabs: connecting an application is the main task here. */}
        <ConnectionDetails
          database={data}
          canManage={canManage}
          phpmyadminSites={phpmyadmin.known ? phpmyadmin.sites : null}
        />

        <UsedByCard
          database={data}
          canManage={canManage}
          applications={appList.applications}
          databaseCounts={dbCounts.counts}
          databasesKnown={dbCounts.known}
          siteTypes={catalogue.siteTypes}
        />

        <DatabaseTabs
          initial={sp?.tab}
          counts={{
            users: data.users?.length ?? 0,
            // Null, not 0, when the list could not be read.
            tables: tables.failed || engineDown ? null : tables.tables.length,
            exports: exportList.failed
              ? null
              : exportList.exports.filter((row) => row.database_id === data.id).length,
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

        <DeleteDatabaseCard
          database={data}
          application={applicationById(appList.applications, data.application_id)}
          canManage={canManage}
        />
      </div>
    </div>
  );
}

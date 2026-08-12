import { redirect, notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getDatabase } from "@/lib/databases/get-database";
import { getExports } from "@/lib/databases/get-exports";
import { getTables } from "@/lib/databases/get-monitor";
import { Badge } from "@/components/ui/badge";
import { DatabaseUsers } from "@/components/databases/database-users";
import { DatabaseFacts } from "@/components/databases/database-facts";
import { ConnectionDetails } from "@/components/databases/connection-details";
import { DatabaseTabs } from "@/components/databases/database-tabs";
import { DatabaseTables } from "@/components/databases/database-tables";
import { DatabaseExports } from "@/components/databases/database-exports";
import { DeleteDatabaseCard } from "@/components/databases/delete-database-card";
import { PageCrumb } from "@/components/sections/page-crumb";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { database } = await params;
  const { data } = await getDatabase(database);
  return { title: data?.name ?? "" };
}

export default async function DatabasePage({ params, searchParams }) {
  const { database: id } = await params;
  const sp = await searchParams;

  const [permissions, t, live, exportList, tables] = await Promise.all([
    getPermissions(),
    getTranslations("databases"),
    getDatabase(id),
    // Exports are global (rows outlive their database), so this is filtered by
    // the card rather than requested per database.
    getExports(),
    getTables(id),
  ]);
  const { data, failed, status, failure } = live;

  if (!can(permissions, "database", "view")) redirect("/dashboard");
  const canManage = can(permissions, "database", "manage");

  // A database that was dropped in another tab is gone, not broken — the 404
  // page says that better than "we couldn't load this".
  if (status === 404) notFound();
  if (failed || !data) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} />;

  return (
    <div className="space-y-6">
      <PageCrumb mono>{data.name}</PageCrumb>

      <div className="space-y-3">
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="font-mono text-2xl font-semibold tracking-tight">
              {data.name}
            </h1>
            <Badge variant="secondary" className="font-normal">
              {t(`engines.${data.engine}`)}
            </Badge>
          </div>
          <DatabaseFacts database={data} />
        </div>

      </div>

      <div className="max-w-4xl space-y-4">
        {/* Above the tabs: connecting an application is what this page is
            opened for, and it was three clicks deep inside Users. */}
        <ConnectionDetails database={data} canManage={canManage} />

        <DatabaseTabs
          initial={sp?.tab}
          counts={{
            users: data.users?.length ?? 0,
            tables: tables.length,
            backups: exportList.exports.filter(
              (row) => row.database_id === data.id,
            ).length,
          }}
          users={<DatabaseUsers database={data} canManage={canManage} />}
          tables={
            <DatabaseTables
              database={data}
              tables={tables}
              canManage={canManage}
            />
          }
          backups={
            <DatabaseExports
              database={data}
              exports={exportList.exports}
              canManage={canManage}
            />
          }
        />

        {/* Outside the tabs: deleting the database is not one of its sections,
            and it belongs at the end of the page past everything that might
            change your mind. */}
        <DeleteDatabaseCard database={data} canManage={canManage} />
      </div>
    </div>
  );
}

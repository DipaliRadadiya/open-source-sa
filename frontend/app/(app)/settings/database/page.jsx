import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { MysqlForm } from "@/components/settings/mysql-form";
import { BinlogForm } from "@/components/settings/binlog-form";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

/**
 * Engine configuration for MySQL / MariaDB.
 *
 * Its own tab rather than a third and fourth card under Performance. Swap and
 * Redis are the server's memory; these are one service's own tuning, and
 * somebody looking for the connection limit looks for the word "Database" —
 * a tab is the only part of this a user can find without being told where to
 * look.
 */
export default async function SettingsDatabasePage() {
  const [permissions, t, { data, lastChanged, failed }] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
  ]);

  const canManage = can(permissions, "setting", "manage");

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  // Absent means no MySQL or MariaDB answered on this box. Nothing is rendered
  // for it — an empty card would be a form for software that isn't there.
  const hasEngine = Boolean(data.mysql || data.mysql_binlog);

  if (!hasEngine) {
    return <p className="text-sm text-muted-foreground">{t("database.noEngine")}</p>;
  }

  return (
    <div className="space-y-4">
      {data.mysql ? (
        <MysqlForm
          mysql={data.mysql}
          canManage={canManage}
          changedBy={await changedFor(lastChanged, "mysql")}
        />
      ) : null}

      {data.mysql_binlog ? (
        <BinlogForm
          binlog={data.mysql_binlog}
          canManage={canManage}
          changedBy={await changedFor(lastChanged, "mysql_binlog")}
        />
      ) : null}
    </div>
  );
}

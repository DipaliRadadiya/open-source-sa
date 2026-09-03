import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSettings } from "@/lib/settings/get-settings";
import { getMemoryTotal } from "@/lib/settings/get-memory-total";
import { SwapForm } from "@/components/settings/swap-form";
import { RedisForm } from "@/components/settings/redis-form";
import { MysqlForm } from "@/components/settings/mysql-form";
import { BinlogForm } from "@/components/settings/binlog-form";
import { changedFor } from "@/lib/settings/changed-for";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export default async function SettingsPerformancePage() {
  const [permissions, t, { data, lastChanged, failed }] = await Promise.all([
    getPermissions(),
    getTranslations("settings"),
    getSettings(),
  ]);

  const canManage = can(permissions, "setting", "manage");

  // Optional context for the swap recommendation; needs the dashboard
  // permission, and the card copes without it.
  const memoryTotal = can(permissions, "dashboard", "view")
    ? await getMemoryTotal()
    : null;

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  return (
    <div className="space-y-4">
      {data.swap ? (
        <SwapForm
          swap={data.swap}
          memoryTotal={memoryTotal}
          canManage={canManage}
        />
      ) : null}

      {/* Absent means Redis isn't installed. Nothing is rendered for it — an
          empty Redis card would be a form for software that isn't there. */}
      {data.redis ? (
        <RedisForm
          redis={data.redis}
          canManage={canManage}
          changedBy={await changedFor(lastChanged, "redis")}
        />
      ) : null}

      {/* Absent means no MySQL or MariaDB answered on this box — the same
          rule as Redis above: no card for software that isn't there. */}
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

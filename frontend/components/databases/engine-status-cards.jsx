import { getTranslations, getFormatter } from "next-intl/server";
import { Card, CardContent } from "@/components/ui/card";

function secondsToHuman(seconds, t) {
  if (!seconds) return null;
  const days = Math.floor(seconds / 86400);
  if (days >= 1) return t("uptimeDays", { days });
  const hours = Math.floor(seconds / 3600);
  if (hours >= 1) return t("uptimeHours", { hours });
  return t("uptimeMinutes", { minutes: Math.max(1, Math.floor(seconds / 60)) });
}

/**
 * The four numbers worth knowing about a database engine.
 *
 * Connections is shown against its ceiling because the number alone means
 * nothing — 40 is fine out of 151 and a crisis out of 50. Mongo returns nulls
 * for the SQL-only fields, so anything missing is left out rather than shown
 * as zero.
 */
export async function EngineStatusCards({ status }) {
  const t = await getTranslations("databases.monitor");
  const format = await getFormatter();
  if (!status) return null;

  const connections =
    status.connections != null
      ? status.max_connections
        ? t("ofMax", {
            used: format.number(status.connections),
            max: format.number(status.max_connections),
          })
        : format.number(status.connections)
      : null;

  const stats = [
    [t("connections"), connections],
    [
      t("threadsRunning"),
      status.threads_running != null ? format.number(status.threads_running) : null,
    ],
    [
      t("slowQueries"),
      status.slow_queries != null ? format.number(status.slow_queries) : null,
    ],
    [t("uptime"), secondsToHuman(status.uptime_seconds, t)],
  ].filter(([, value]) => value != null);

  if (stats.length === 0) return null;

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {stats.map(([label, value]) => (
        <Card key={label} className="py-0">
          <CardContent className="space-y-1 px-4 py-3.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-xl font-semibold tabular-nums">{value}</p>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

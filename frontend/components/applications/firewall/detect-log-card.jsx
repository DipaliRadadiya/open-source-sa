import { getFormatter, getTranslations } from "next-intl/server";
import { FileQuestion, ShieldCheck } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { RefreshButton } from "@/components/data-table/refresh-button";

// Deliberately no "allow requests like this": an exception skips all six checks for any URL, query
// or user agent containing it, so one click could silently disable the firewall.
export async function DetectLogCard({ rows = [], failed = false }) {
  const t = await getTranslations("applications.firewall.detect");
  const format = await getFormatter();

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex min-w-48 flex-1 items-center gap-2.5">
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
            <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {rows.length ? (
            <Badge variant="secondary" className="shrink-0 font-normal">
              {t("count", { count: rows.length })}
            </Badge>
          ) : null}
          <RefreshButton className="size-8" />
        </div>
      </div>

      <CardContent className="p-3 sm:p-5">
        {/* Both are ordinary states; an empty log is normal (it is created by the first match). */}
        {failed || rows.length === 0 ? (
          <EmptyState compact icon={failed ? FileQuestion : ShieldCheck} badge={null} title={failed ? t("unreadable") : t("empty")} />
        ) : (
          <div className="divide-y rounded-lg border">
            {rows.map((row, i) => (
              <div key={`${row.at ?? ""}-${row.ip}-${i}`} className="space-y-1 px-4 py-3">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                  <Badge variant="outline" className="shrink-0 font-mono text-xs font-normal">
                    {row.method}
                  </Badge>
                  {/* break-all, not truncate: the tail of an injection attempt shows why it matched. */}
                  <span className="min-w-48 flex-1 break-all font-mono text-xs">{row.target}</span>
                </div>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                  <span className="font-mono">{row.ip}</span>
                  {row.at ? (
                    <>
                      <span aria-hidden>·</span>
                      <span>
                        {format.dateTime(new Date(row.at), {
                          dateStyle: "medium",
                          timeStyle: "short",
                        })}
                      </span>
                    </>
                  ) : null}
                  {row.userAgent ? (
                    <>
                      <span aria-hidden>·</span>
                      <span className="min-w-0 truncate">{row.userAgent}</span>
                    </>
                  ) : null}
                </p>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

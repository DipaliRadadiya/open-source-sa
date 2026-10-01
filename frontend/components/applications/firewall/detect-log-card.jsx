import { getFormatter, getTranslations } from "next-intl/server";
import { Eye, FileQuestion, ShieldCheck } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { RefreshButton } from "@/components/data-table/refresh-button";

/**
 * What watching mode caught, shown in place.
 *
 * Deliberately does NOT offer "allow requests like this": an exception is a
 * substring tested against URL, query string AND user agent and skips all six
 * checks, so a one-click exception could silently disable the firewall for a
 * whole slice of the site. Same shape as the bot-blocker traffic card.
 */
export async function DetectLogCard({ rows = [], failed = false }) {
  const t = await getTranslations("applications.firewall.detect");
  const format = await getFormatter();

  return (
    <Card className="max-w-4xl gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/20 px-5 py-3">
        <div className="flex min-w-48 flex-1 items-center gap-2.5">
          <Eye className="size-4 shrink-0 text-muted-foreground" />
          <div className="min-w-0">
            <p className="text-sm font-medium">{t("title")}</p>
            <p className="text-xs text-muted-foreground">{t("subtitle")}</p>
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
          <div className="flex flex-col items-center gap-2 py-6 text-center">
            <span className="flex size-9 items-center justify-center rounded-full bg-muted-foreground/10 text-muted-foreground">
              {failed ? <FileQuestion className="size-4" /> : <ShieldCheck className="size-4" />}
            </span>
            <p className="max-w-sm text-sm text-muted-foreground">
              {failed ? t("unreadable") : t("empty")}
            </p>
          </div>
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

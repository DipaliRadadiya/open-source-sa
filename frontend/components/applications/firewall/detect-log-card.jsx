import { getFormatter, getTranslations } from "next-intl/server";
import { Eye, FileQuestion, ShieldCheck } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

/**
 * What watching mode caught — read here rather than sending someone to the file
 * browser to find a log file themselves.
 *
 * Deliberately does NOT offer "allow requests like this". An exception is a
 * literal substring tested against the URL, the query string AND the
 * user-agent, and one match skips all six checks — so a one-click exception
 * built from a log row would switch the firewall off across a whole slice of
 * the site while reading like a small, local fix. Until the log records which
 * check matched and an exception can be scoped to it, the safe affordance is to
 * show the evidence and let someone write the exception deliberately.
 *
 * Same shape as the bot-blocker traffic card: this is the other "here is what
 * actually hit your site" panel and they should read as one family.
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
        {rows.length ? (
          <Badge variant="secondary" className="shrink-0 font-normal">
            {t("count", { count: rows.length })}
          </Badge>
        ) : null}
      </div>

      <CardContent className="p-3 sm:p-5">
        {/* Both of these are ordinary states. A quiet icon and centred text says
            "nothing to report", not "something broke" — and an empty file is
            the NORMAL state here, because it is created by the first match. */}
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
                  {/* break-all, not truncate: a caught request is usually a long
                      injection attempt, and cutting it hides the part that
                      shows why it looked like one. */}
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

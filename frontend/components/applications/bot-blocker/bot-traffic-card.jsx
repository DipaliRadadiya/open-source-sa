import Link from "next/link";
import { getFormatter, getTranslations } from "next-intl/server";
import { Activity, Bot, FileQuestion } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

// The ranges the backend accepts (it caps at 90). Kept short: this is a
// "what's been hitting me lately" question, not an analytics history.
export const TRAFFIC_RANGES = [7, 30, 90];
export const DEFAULT_RANGE = 7;

// A bounded, already-sorted list of at most a few dozen rows — a plain Table,
// not the DataTable. Nobody needs to paginate or filter their own bot list.
const CATEGORY_LABELS = new Set(["training", "search", "agent", "custom"]);

/**
 * Which AI bots actually visited, and what the current settings do to each.
 *
 * This is the difference between picking a policy blind and picking one from
 * evidence — it is the reason the endpoint was asked for. It sits BELOW the
 * choices rather than above them: on most sites it is empty or unreadable, and
 * an empty panel must not push the actual control off the screen.
 */
export async function BotTrafficCard({ appId, traffic, failed, days }) {
  const t = await getTranslations("applications.botBlocker.traffic");
  // The summary line gets thousands separators from its own ICU formatting;
  // the column has to match, or the same card reads "3,481 requests" above a
  // row that says "1841".
  const format = await getFormatter();

  // A failed request and a log that could not be read are the same thing to a
  // reader: no evidence. Neither is allowed to render as "nothing visits you".
  const status = failed || !traffic ? "unavailable" : traffic.status;
  const bots = traffic?.bots ?? [];
  const totals = traffic?.totals ?? { bots: 0, hits: 0, blocked_hits: 0 };

  return (
    <Card className="max-w-4xl gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/20 px-5 py-3">
        <div className="flex items-center gap-2.5">
          <Activity className="size-4 shrink-0 text-muted-foreground" />
          <div>
            <p className="text-sm font-medium">{t("title")}</p>
            <p className="text-xs text-muted-foreground">{t("subtitle")}</p>
          </div>
        </div>
        {/* Plain links, so the range is in the URL and the server component
            re-runs — no client state for something a page reload should keep. */}
        <div className="flex gap-1">
          {TRAFFIC_RANGES.map((range) => (
            <Button
              key={range}
              asChild
              size="sm"
              variant={range === days ? "secondary" : "ghost"}
              className={cn("h-7 px-2 text-xs", range === days && "font-medium")}
            >
              <Link
                href={
                  range === DEFAULT_RANGE
                    ? `/applications/${appId}/bot-blocker`
                    : `/applications/${appId}/bot-blocker?days=${range}`
                }
                scroll={false}
              >
                {t("range", { days: range })}
              </Link>
            </Button>
          ))}
        </div>
      </div>

      <CardContent className="p-3 sm:p-5">
        {/* A lone grey sentence in an otherwise blank card reads as a failure.
            Both of these are ordinary, expected states — a quiet icon and
            centred text says "nothing to report", not "something broke". */}
        {status === "unavailable" || bots.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-6 text-center">
            <span className="flex size-9 items-center justify-center rounded-full bg-muted-foreground/10 text-muted-foreground">
              {status === "unavailable" ? (
                <FileQuestion className="size-4" />
              ) : (
                <Bot className="size-4" />
              )}
            </span>
            <p className="max-w-sm text-sm text-muted-foreground">
              {status === "unavailable" ? t("unavailable") : t("empty", { days })}
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
              {t("summary", {
                bots: totals.bots,
                hits: totals.hits,
                blocked: totals.blocked_hits,
              })}
            </p>

            <div className="overflow-hidden rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="px-2 sm:px-4">{t("columns.bot")}</TableHead>
                    {/* Five columns do not fit a phone. Rather than let the
                        table clip — which hid "Right now", the one column that
                        answers "is this bot getting in?" — the two least
                        urgent drop out below `sm` and reappear above it. */}
                    <TableHead className="hidden sm:table-cell">{t("columns.kind")}</TableHead>
                    <TableHead className="px-2 text-right sm:px-4">{t("columns.requests")}</TableHead>
                    <TableHead className="hidden md:table-cell">{t("columns.lastSeen")}</TableHead>
                    <TableHead className="px-2 sm:px-4">{t("columns.status")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {bots.map((bot) => (
                    <TableRow key={bot.bot}>
                      <TableCell className="px-2 font-mono text-xs sm:px-4">
                        {bot.bot}
                        {/* The kind still has to be readable once its own
                            column is gone, so it rides along under the name. */}
                        <span className="block text-xs font-sans text-muted-foreground sm:hidden">
                          {CATEGORY_LABELS.has(bot.category)
                            ? t(`kinds.${bot.category}`)
                            : (bot.category ?? "—")}
                        </span>
                      </TableCell>
                      <TableCell className="hidden text-xs text-muted-foreground sm:table-cell">
                        {CATEGORY_LABELS.has(bot.category)
                          ? t(`kinds.${bot.category}`)
                          : (bot.category ?? "—")}
                      </TableCell>
                      <TableCell className="px-2 text-right text-xs tabular-nums sm:px-4">
                        {format.number(bot.hits)}
                      </TableCell>
                      <TableCell className="hidden text-xs text-muted-foreground md:table-cell">
                        {bot.last_seen_human ?? "—"}
                      </TableCell>
                      <TableCell className="px-2 sm:px-4">
                        {/* Says what your CURRENT settings do to this bot, so
                            the table doubles as a preview of the policy above
                            rather than a list to cross-reference by hand. */}
                        {bot.blocked ? (
                          <Badge variant="secondary">{t("blocked")}</Badge>
                        ) : (
                          <span className="text-xs text-muted-foreground">{t("allowed")}</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {/* Said rather than hidden: a truncated scan is a partial answer,
                and a partial answer presented as a whole one is how someone
                concludes a bot never visits them. */}
            {status === "partial" ? (
              <p className="text-xs text-warning">{t("partial")}</p>
            ) : null}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

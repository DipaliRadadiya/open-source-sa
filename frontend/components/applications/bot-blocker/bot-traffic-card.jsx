import Link from "@/components/ui/app-link";
import { getFormatter, getTranslations } from "next-intl/server";
import { Activity, Bot, FileQuestion } from "lucide-react";
import { cn } from "@/lib/utils";
import { filterToggleClass } from "@/lib/theme/filter-toggle";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

// Ranges in days; the backend caps at 90.
export const TRAFFIC_RANGES = [7, 30, 90];
export const DEFAULT_RANGE = 7;

// The list is small and pre-sorted, so a plain Table rather than DataTable.
const CATEGORY_LABELS = new Set(["training", "search", "agent", "custom"]);

/**
 * Which AI bots visited, and what the current settings do to each. Placed
 * below the controls, since it is often empty.
 */
export async function BotTrafficCard({ appId, traffic, failed, days }) {
  const t = await getTranslations("applications.botBlocker.traffic");
  // Formats counts to match the ICU-formatted summary line.
  const format = await getFormatter();

  // A failed request or unreadable log must never render as "no bots".
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
        {/* Links, so the range lives in the URL and the server component re-runs. */}
        <div className="flex items-center gap-1">
          <RefreshButton className="me-1 size-8" />
          {TRAFFIC_RANGES.map((range) => (
            <Button
              key={range}
              asChild
              size="sm"
              /* Outlined so it reads as clickable; active style shared with
                 the log viewer's filter via filterToggleClass. */
              variant="field"
              className={cn("h-8 px-2.5 text-xs", filterToggleClass(range === days))}
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
        {/* Both are ordinary states, shown as a quiet empty state, not an error. */}
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
                    {/* The two least urgent columns hide below `sm` so the
                        settings column stays visible on phones. */}
                    <TableHead className="hidden sm:table-cell">{t("columns.kind")}</TableHead>
                    <TableHead className="h-auto px-2 py-2 text-right whitespace-normal sm:px-4">{t("columns.requests")}</TableHead>
                    {/* From xl: longer locales overflow the table at md and lg. */}
                    <TableHead className="hidden xl:table-cell">{t("columns.lastSeen")}</TableHead>
                    {/* Allowed to wrap: longer locales overflow a 390px screen. */}
                    <TableHead className="h-auto px-2 py-2 whitespace-normal sm:px-4">{t("columns.status")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {bots.map((bot) => (
                    <TableRow key={bot.bot}>
                      {/* Breaks only names too long for the screen, not every word. */}
                      <TableCell
                        className={cn(
                          "px-2 font-mono text-xs sm:px-4",
                          bot.bot.length > 24 && "min-w-20 break-all whitespace-normal",
                        )}
                      >
                        {bot.bot}
                        {/* Shows the kind under the name when its column is hidden. */}
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
                      <TableCell className="hidden text-xs text-muted-foreground xl:table-cell">
                        {bot.last_seen_human ?? "—"}
                      </TableCell>
                      <TableCell className="px-2 sm:px-4">
                        {/* The configured policy for this bot, decided by name;
                            not whether its logged requests were blocked. */}
                        {bot.blocked ? (
                          <Badge variant="muted">{t("blocked")}</Badge>
                        ) : (
                          <span className="text-xs text-muted-foreground">{t("allowed")}</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {/* A truncated scan is flagged so it is not read as complete. */}
            {status === "partial" ? (
              <p className="text-xs text-warning">{t("partial")}</p>
            ) : null}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

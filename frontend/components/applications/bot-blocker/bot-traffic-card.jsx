import Link from "@/components/ui/app-link";
import { getFormatter, getTranslations } from "next-intl/server";
import { Bot, FileQuestion } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { cn } from "@/lib/utils";
import { filterToggleClass } from "@/lib/theme/filter-toggle";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

export const TRAFFIC_RANGES = [7, 30, 90];
export const DEFAULT_RANGE = 7;

const CATEGORY_LABELS = new Set(["training", "search", "agent", "custom", "legacy"]);

export async function BotTrafficCard({ appId, traffic, failed, days }) {
  const t = await getTranslations("applications.botBlocker.traffic");
  const format = await getFormatter();

  const status = failed || !traffic ? "unavailable" : traffic.status;
  const bots = traffic?.bots ?? [];
  const totals = traffic?.totals ?? { bots: 0, hits: 0, blocked_hits: 0 };

  return (
    <Card >
      <CardHeader className="flex flex-wrap items-center justify-between gap-3">
        <CardTitle icon={Bot} as="h2" className="min-w-0">{t("title")}</CardTitle>
        <div className="flex items-center gap-1">
          <RefreshButton className="me-1 size-8" />
          {TRAFFIC_RANGES.map((range) => (
            <Button
              key={range}
              asChild
              size="sm"
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
      </CardHeader>

      <CardContent>
        {status === "unavailable" || bots.length === 0 ? (
          <EmptyState
            compact
            icon={status === "unavailable" ? FileQuestion : Bot}
            badge={null}
            title={status === "unavailable" ? t("unavailable") : t("empty", { days })}
          />
        ) : (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
              {t("summary", {
                bots: totals.bots,
                hits: totals.hits,
                blocked: totals.blocked_hits,
              })}
            </p>

            <div className="-mx-(--card-spacing) border-y">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="px-2 sm:px-4">{t("columns.bot")}</TableHead>
                    <TableHead className="hidden sm:table-cell">{t("columns.kind")}</TableHead>
                    <TableHead className="h-auto px-2 py-2 text-right whitespace-normal sm:px-4">{t("columns.requests")}</TableHead>
                    <TableHead className="hidden xl:table-cell">{t("columns.lastSeen")}</TableHead>
                    <TableHead className="h-auto px-2 py-2 whitespace-normal sm:px-4">{t("columns.status")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {bots.map((bot) => (
                    <TableRow key={bot.bot}>
                      <TableCell
                        className={cn(
                          "px-2 font-mono text-xs sm:px-4",
                          bot.bot.length > 24 && "min-w-20 break-all whitespace-normal",
                        )}
                      >
                        {bot.bot}
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
                        {bot.blocked_hits > 0 ? (
                          <span className="block text-muted-foreground">{t("blockedHits", { count: bot.blocked_hits })}</span>
                        ) : null}
                      </TableCell>
                      <TableCell className="hidden text-xs text-muted-foreground xl:table-cell">
                        {bot.last_seen_human ?? "—"}
                      </TableCell>
                      <TableCell className="px-2 sm:px-4">
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

            {status === "partial" ? (
              <p className="text-xs text-warning">{t("partial")}</p>
            ) : null}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

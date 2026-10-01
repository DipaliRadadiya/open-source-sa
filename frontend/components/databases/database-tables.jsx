"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Table2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { Card, CardContent } from "@/components/ui/card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { RefreshButton } from "@/components/data-table/refresh-button";

// Names and sizes only: the API returns nothing more. Read-only by design
// (Optimize/Repair are unreliable on InnoDB).
export function DatabaseTables({ database, tables = [], read = null, unavailable = null }) {
  const t = useTranslations("databases.tables");
  const format = useFormatter();

  // Mongo has collections, not tables, and uses its own wording.
  const isMongo = database?.driver === "mongo";

  const totalRows = tables.reduce((sum, table) => sum + (table.rows ?? 0), 0);
  const estimated = tables.length > 0 && !isMongo;

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <Table2 className="size-3.5" />
          </span>
          <div>
            <h2 className="text-base font-semibold tracking-tight">
              {t(isMongo ? "titleMongo" : "title")}
            </h2>
            <p className="text-sm text-muted-foreground">
              {tables.length
                ? t(isMongo ? "summaryMongo" : "summary", {
                    count: tables.length,
                    rows: format.number(totalRows),
                  })
                : t("description")}
            </p>
          </div>
        </div>
        <RefreshButton />
      </div>

      <CardContent className="px-5 py-0">
        {unavailable ? (
          <div className="py-8 text-center">
            <p className="text-sm text-muted-foreground">{t("unavailable", { engine: unavailable })}</p>
          </div>
        ) : read?.failed ? (
          <div className="py-5">
            <LoadFailed
              description={t("loadFailed")}
              status={read.status}
              failure={read.failure}
              message={read.message}
            />
          </div>
        ) : tables.length === 0 ? (
          <div className="py-8 text-center">
            <p className="text-sm text-muted-foreground">{t("empty")}</p>
          </div>
        ) : (
          /* Bounded: apps like Nextcloud ship ~150 tables. */
          <div className="max-h-96 divide-y overflow-y-auto">
            {tables.map((table) => (
              <div
                key={table.name}
                className="flex items-center justify-between gap-4 py-2.5"
              >
                <span className="truncate font-mono text-sm">{table.name}</span>
                <span className="flex shrink-0 items-center gap-4 text-sm text-muted-foreground">
                  {/* InnoDB row counts are estimates; labelled as such. */}
                  <span className="tabular-nums">
                    {t("rowCount", { rows: format.number(table.rows ?? 0) })}
                  </span>
                  <span className="w-16 text-right tabular-nums">
                    {formatBytes(table.size_bytes ?? 0, format)}
                  </span>
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      {estimated || database?.collation ? (
        <div className="space-y-1 border-t bg-muted/30 px-5 py-3">
          {database?.collation ? (
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("charsetLine", {
                charset: database.charset ?? "—",
                collation: database.collation,
              })}
            </p>
          ) : null}
          {estimated ? (
            <p className="text-xs leading-relaxed text-muted-foreground">
              {t("rowsEstimated")}
            </p>
          ) : null}
        </div>
      ) : null}
    </Card>
  );
}

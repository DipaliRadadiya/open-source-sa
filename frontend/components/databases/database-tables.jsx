"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Table2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { Card, CardContent } from "@/components/ui/card";

/**
 * What is inside the database.
 *
 * Deliberately not a data browser — the API returns names and sizes only, and
 * browsing rows is phpMyAdmin's job. This answers "why is this database 2 GB",
 * which is the question a size figure on the list page raises and cannot
 * answer.
 *
 * It used to carry Optimize and Repair buttons. Both were removed on
 * 2026-09-08 along with their endpoints, because neither did what its label
 * said: `REPAIR TABLE` is unsupported on InnoDB and reports success anyway, and
 * `OPTIMIZE TABLE` on InnoDB is a whole-table rebuild that ran inside the HTTP
 * request. Read-only now, which is what the card was always for.
 */
export function DatabaseTables({ database, tables = [] }) {
  const t = useTranslations("databases.tables");
  const format = useFormatter();

  // Mongo has collections, not tables, and says so in its own words.
  const isMongo = database?.driver === "mongo";

  const totalRows = tables.reduce((sum, table) => sum + (table.rows ?? 0), 0);
  const estimated = tables.length > 0 && !isMongo;

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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
      </div>

      <CardContent className="px-5 py-0">
        {tables.length === 0 ? (
          <div className="py-8 text-center">
            <p className="text-sm text-muted-foreground">{t("empty")}</p>
          </div>
        ) : (
          /* Bounded, because the list is every table in the database and
               that is not a small number for the applications this panel
               installs — Nextcloud ships ~150, a mature WordPress more.
               Unbounded, the card grew to whatever the schema happened to be
               and pushed the charset footer, and everything below the card,
               off the screen. Roughly ten rows before it scrolls; short
               lists never show a scrollbar. */
          <div className="max-h-96 divide-y overflow-y-auto">
            {tables.map((table) => (
              <div
                key={table.name}
                className="flex items-center justify-between gap-4 py-2.5"
              >
                <span className="truncate font-mono text-sm">{table.name}</span>
                <span className="flex shrink-0 items-center gap-4 text-sm text-muted-foreground">
                  {/* Row counts on InnoDB are an estimate, and saying so
                        beats someone treating it as a total. */}
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

      {/* Charset and collation belong to the storage, not to the page
            heading where they used to sit. Read once, if ever. */}
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

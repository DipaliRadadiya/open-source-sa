"use client";

import { useMemo, useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { ArrowDown, ArrowUp, ArrowUpDown, SearchX, Table2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { formatBytes } from "@/lib/format/bytes";
import { EmptyState } from "@/components/data-table/empty-state";
import { Card } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { LoadFailed } from "@/components/data-table/load-failed";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { LocalSearchInput } from "@/components/data-table/local-search-input";

// A filter only once the list is long enough to need one.
const FILTER_FROM = 8;

// Names, row estimates and sizes only: the API returns nothing more. Read-only by
// design (Optimize/Repair are unreliable on InnoDB).
export function DatabaseTables({ database, tables = [], read = null, unavailable = null }) {
  const t = useTranslations("databases.tables");
  const format = useFormatter();
  const [query, setQuery] = useState("");
  // Largest first, as the API sends them: the big tables are what people look for.
  const [sort, setSort] = useState({ key: "size", desc: true });

  // Mongo has collections, not tables, and uses its own wording.
  const isMongo = database?.driver === "mongo";

  const totalRows = tables.reduce((sum, table) => sum + (table.rows ?? 0), 0);
  const largest = Math.max(1, ...tables.map((table) => table.size_bytes ?? 0));
  const estimated = tables.length > 0 && !isMongo;

  const shown = useMemo(() => {
    const term = query.trim().toLowerCase();
    const value = (table) =>
      sort.key === "name" ? table.name : sort.key === "rows" ? (table.rows ?? 0) : (table.size_bytes ?? 0);
    return tables
      .filter((table) => !term || table.name.toLowerCase().includes(term))
      .sort((a, b) => {
        const [x, y] = [value(a), value(b)];
        const order = typeof x === "string" ? x.localeCompare(y) : x - y;
        return sort.desc ? -order : order;
      });
  }, [tables, query, sort]);

  function header(key, label, className) {
    const active = sort.key === key;
    const Icon = !active ? ArrowUpDown : sort.desc ? ArrowDown : ArrowUp;
    return (
      <th
        scope="col"
        aria-sort={active ? (sort.desc ? "descending" : "ascending") : "none"}
        className={cn("h-10 px-3 font-medium sm:px-5", className)}
      >
        <button
          type="button"
          // Text columns start A→Z, number columns largest first.
          onClick={() => setSort({ key, desc: active ? !sort.desc : key !== "name" })}
          className={cn(
            "inline-flex items-center gap-1 rounded hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring",
            active && "text-foreground",
          )}
        >
          {label}
          <Icon className={cn("size-3.5", !active && "opacity-50")} aria-hidden />
        </button>
      </th>
    );
  }

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex min-w-48 flex-1 items-center gap-3">
          <CardIcon icon={Table2} />
          <div>
            <h2 className="text-[15px] font-semibold tracking-tight">
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
        <div className="flex w-full items-center gap-2 sm:w-auto">
          {tables.length > FILTER_FROM ? (
            <LocalSearchInput
              value={query}
              onChange={setQuery}
              placeholder={t("filter")}
              className="min-w-0 flex-1 sm:w-56 sm:flex-none"
            />
          ) : null}
          <RefreshButton />
        </div>
      </div>

      {unavailable ? (
        <div className="px-5 py-8 text-center">
          <p className="text-sm text-muted-foreground">{t("unavailable", { engine: unavailable })}</p>
        </div>
      ) : read?.failed ? (
        <div className="px-5 py-5">
          <LoadFailed
            description={t("loadFailed")}
            status={read.status}
            failure={read.failure}
            message={read.message}
          />
        </div>
      ) : tables.length === 0 ? (
        <div className="px-5 py-5">
          <EmptyState compact icon={Table2} badge={null} title={t("empty")} />
        </div>
      ) : shown.length === 0 ? (
        <div className="px-5 py-5">
          <EmptyState compact icon={SearchX} badge="search" title={t("noMatches")} />
        </div>
      ) : (
        /* Bounded: apps like Nextcloud ship ~150 tables. */
        <div className="max-h-[28rem] overflow-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 z-10 bg-muted/60 text-left text-[13px] text-muted-foreground backdrop-blur">
              <tr className="border-b">
                {/* Shares of the width, not "the name takes what is left": that pushed Rows
                    and Size together at the right edge with the middle empty (Krishna, 8 Oct). */}
                {header("name", isMongo ? t("columns.collection") : t("columns.table"), "sm:w-[40%]")}
                {header("rows", isMongo ? t("columns.documents") : t("columns.rows"), "text-right sm:w-[20%]")}
                {header("size", t("columns.size"), "text-right sm:w-[40%]")}
              </tr>
            </thead>
            <tbody className="divide-y">
              {shown.map((table) => (
                <tr key={table.name} className="hover:bg-muted/30">
                  {/* Phone: no icon, no "rows" word, tighter padding, so names are not cut to three letters. */}
                  <td className="max-w-0 px-3 py-2.5 sm:px-5">
                    <span className="flex min-w-0 items-center gap-2">
                      <Table2 className="hidden size-4 shrink-0 text-muted-foreground sm:block" aria-hidden />
                      <span className="truncate font-mono font-medium" title={table.name}>{table.name}</span>
                    </span>
                  </td>
                  {/* InnoDB row counts are estimates; labelled as such below. */}
                  <td className="px-3 py-2.5 text-right whitespace-nowrap text-muted-foreground tabular-nums sm:px-5">
                    <span className="font-medium text-foreground">{format.number(table.rows ?? 0)}</span>
                    <span className="hidden sm:inline">
                      {" "}
                      {t(isMongo ? "rowsWordMongo" : "rowsWord", { count: table.rows ?? 0 })}
                    </span>
                  </td>
                  <td className="px-3 py-2.5 sm:px-5">
                    <span className="flex items-center justify-end gap-3">
                      {/* Share of the largest table, so the heavy ones stand out. A short,
                          fixed bar beside the size (Krishna, 8 Oct: not a long one). */}
                      <span aria-hidden className="hidden h-1.5 w-24 overflow-hidden rounded-full bg-muted sm:block">
                        <span
                          className="block h-full rounded-full bg-primary/70"
                          style={{ width: `${Math.max(4, ((table.size_bytes ?? 0) / largest) * 100)}%` }}
                        />
                      </span>
                      <span className="w-16 text-right font-medium tabular-nums">
                        {formatBytes(table.size_bytes ?? 0, format)}
                      </span>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

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

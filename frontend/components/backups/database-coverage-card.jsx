"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Database, Settings2, ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { DataTable } from "@/components/ui/data-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { RunsCell, ScheduleCell, StorageCell } from "@/components/backups/coverage-table";

/**
 * Databases that are not part of an application (RC-23), under the applications'
 * coverage: a site's database is backed up with the site, these have schedules of
 * their own, set on the database's Backups tab.
 */
export function DatabaseCoverageCard({ coverage, options, canOpenDatabases = false }) {
  const t = useTranslations("backups.databases");
  const tc = useTranslations("backups.coverage");

  // Nothing to say when every database belongs to a site.
  if (!coverage.failed && coverage.rows.length === 0) return null;

  const columns = [
    {
      id: "database",
      header: t("columns.database"),
      cell: ({ row }) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
            <Database className="size-4 text-muted-foreground" />
          </span>
          <div className="min-w-0">
            <p className="truncate font-mono text-[13px] font-medium">{row.original.database.name}</p>
            {row.original.database.engine ? (
              <Badge variant="outline" className="mt-0.5 font-normal">{row.original.database.engine}</Badge>
            ) : null}
          </div>
        </div>
      ),
    },
    { id: "schedule", header: tc("columns.schedule"), meta: { className: "w-32" }, cell: (ctx) => <ScheduleCell {...ctx} options={options} /> },
    { id: "storage", header: tc("columns.storage"), meta: { className: "w-32" }, cell: StorageCell },
    { id: "runs", header: tc("columns.lastRun"), meta: { className: "w-36" }, cell: RunsCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{tc("columns.actions")}</span>,
      meta: { className: "w-40 text-right" },
      cell: ({ row }) =>
        canOpenDatabases ? (
          <Button asChild variant="outline" size="sm">
            <Link href={`/databases/${row.original.database.id}?tab=backups`} prefetch={false}>
              {row.original.target ? <Settings2 className="size-4" /> : <ShieldCheck className="size-4" />}
              {row.original.target ? t("manage") : tc("setUpShort")}
            </Link>
          </Button>
        ) : null,
    },
  ];

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="min-w-0">
          <h2 className="flex items-center gap-2 text-[15px] font-semibold tracking-tight">
            {t("title")}
            {!coverage.failed ? (
              <span className="text-xs font-normal tabular-nums text-muted-foreground">{coverage.total}</span>
            ) : null}
          </h2>
          <p className="text-sm text-muted-foreground">{t("description")}</p>
        </div>
      </div>
      {coverage.failed ? (
        <div className="p-5">
          <LoadFailed description={t("loadFailed")} status={coverage.status} failure={coverage.failure} message={coverage.message} />
        </div>
      ) : (
        <div className="overflow-x-auto">
          <DataTable
            bare
            roomy
            columns={columns}
            data={coverage.rows}
            rowId={(row) => String(row.database.id)}
            // A database with no schedule gets one sentence across the three columns.
            spanCells={{
              columns: ["schedule", "storage", "runs"],
              render: (row) => (row.target ? null : <span className="text-sm whitespace-normal text-muted-foreground/80">{t("notSetUpLine")}</span>),
            }}
          />
        </div>
      )}
    </Card>
  );
}

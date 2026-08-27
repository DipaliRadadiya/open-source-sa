"use client";

import { useMemo, useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { SearchX, CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { KillProcessButton } from "@/components/server-dashboard/kill-process-button";

function num(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

// Inline usage bar so a busy process is visible without reading every number.
function UsageCell({ value, tone, label, format }) {
  const n = num(value);
  const text = format.number(n / 100, {
    style: "percent",
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  });
  return (
    <div className="flex items-center gap-2">
      <span className="w-16 shrink-0 font-medium tabular-nums">{text}</span>
      <span
        role="progressbar"
        aria-label={label}
        aria-valuenow={Math.round(n)}
        aria-valuemin={0}
        aria-valuemax={100}
        className="hidden h-1.5 w-28 overflow-hidden rounded-full bg-primary/15 xl:block"
      >
        <span
          className={cn("block h-full rounded-full", tone)}
          style={{ width: `${Math.min(100, n)}%` }}
        />
      </span>
    </div>
  );
}

/* ---------------------------------------------------------------------------
 * Cells are module-level components: flexRender calls `createElement(cellFn)`,
 * so an inline cell gets a new component type on every render and React
 * remounts the whole cell. This table re-renders on every keystroke in the
 * search box above it.
 * ------------------------------------------------------------------------- */

function PidCell({ row }) {
  return <span className="tabular-nums text-muted-foreground">{row.original.pid}</span>;
}

function UserCell({ row }) {
  return <span className="whitespace-nowrap">{row.original.user || "—"}</span>;
}

function CpuCell({ row }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  return (
    <UsageCell
      value={row.original.cpu}
      tone={num(row.original.cpu) >= 50 ? "bg-warning" : "bg-primary"}
      label={t("processes.cpu")}
      format={format}
    />
  );
}

function MemoryCell({ row }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  return (
    <UsageCell
      value={row.original.memory}
      tone="bg-chart-2"
      label={t("processes.memory")}
      format={format}
    />
  );
}

function ActionsCell({ row, table }) {
  return (
    <KillProcessButton process={row.original} canManage={table.options.meta.canManage} />
  );
}

function CommandCell({ row }) {
  if (!row.original.command) {
    return (
      <span className="block w-full truncate font-mono text-xs text-muted-foreground">—</span>
    );
  }
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          tabIndex={0}
          className="block w-full truncate font-mono text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {row.original.command}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-sm font-mono text-xs break-all">
        {row.original.command}
      </TooltipContent>
    </Tooltip>
  );
}

/**
 * `limit` renders the same table with fewer rows, so the collapsed dashboard
 * card and the expanded one share one shape — expanding adds rows rather than
 * swapping a bespoke list for a table. Only the summary footer and the scroll
 * cap belong to the full view; the stop button stays, because spotting a
 * runaway process in the top three and then having to expand before you can do
 * anything about it is friction in exactly the case the preview exists for.
 */
export function ProcessTable({
  data,
  query = "",
  failed = false,
  canManage = false,
  limit = null,
}) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return data;
    return data.filter(
      (p) =>
        String(p.command ?? "").toLowerCase().includes(q) ||
        String(p.user ?? "").toLowerCase().includes(q) ||
        String(p.pid ?? "").includes(q),
    );
  }, [data, query]);

  const rows = limit ? filtered.slice(0, limit) : filtered;

  const columns = [
    {
      accessorKey: "command",
      header: t("processes.command"),
      enableSorting: false,
      // The command is the row's identity, so it owns every pixel left after
      // the compact fact columns. A minimum keeps it useful before the table
      // falls back to horizontal scrolling; max-w-0 lets its child ellipsize.
      meta: { className: "w-full min-w-64 max-w-0" },
      cell: CommandCell,
    },
    {
      id: "pid",
      // pid arrives as number or string; sort it numerically either way.
      accessorFn: (row) => num(row.pid),
      sortingFn: "basic",
      header: t("processes.pid"),
      meta: { className: "w-20" },
      cell: PidCell,
    },
    {
      accessorKey: "user",
      header: t("processes.user"),
      meta: { className: "w-28 max-w-28 truncate" },
      cell: UserCell,
    },
    {
      id: "cpu",
      accessorFn: (row) => num(row.cpu),
      sortingFn: "basic",
      header: t("processes.cpu"),
      meta: { className: "w-32 xl:w-52" },
      cell: CpuCell,
    },
    {
      id: "memory",
      accessorFn: (row) => num(row.memory),
      sortingFn: "basic",
      header: t("processes.memory"),
      meta: { className: "w-32 xl:w-52" },
      cell: MemoryCell,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("processes.actions")}</span>,
      enableSorting: false,
      meta: { className: "w-12 text-right" },
      cell: ActionsCell,
    },
  ];

  if (failed) {
    return <EmptyState icon={CircleAlert} title={t("loadFailed")} />;
  }

  if (rows.length === 0) {
    return query ? (
      <EmptyState icon={SearchX} title={t("processes.noMatch")} />
    ) : (
      <EmptyState icon={SearchX} title={t("processes.empty")} />
    );
  }

  return (
    <div className="space-y-3">
      {/* Fixed-height scroll area keeps the page short no matter how many
          processes the server reports. */}
      <div
        className={cn(
          "overflow-auto rounded-xl border [scrollbar-gutter:stable] [&_table]:min-w-[48rem] [&>div]:rounded-none [&>div]:border-0",
          // Only the full table needs capping; three rows never reach it.
          !limit && "max-h-[26rem]",
        )}
      >
        <DataTable
          columns={columns}
          data={rows}
          meta={{ canManage }}
          sortable={!limit}
          stickyHeader={!limit}
          // Busiest first — the reason to look at this table at all.
          defaultSorting={[{ id: "cpu", desc: true }]}
        />
      </div>
      {limit ? null : (
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          {t("processes.showing", { shown: filtered.length, total: data.length })}
        </p>
        <p className="text-sm tabular-nums text-muted-foreground">
          {t("processes.summary", {
            count: data.length,
            cpu: format.number(data.reduce((sum, p) => sum + num(p.cpu), 0), { maximumFractionDigits: 1 }),
            memory: format.number(data.reduce((sum, p) => sum + num(p.memory), 0), { maximumFractionDigits: 1 }),
          })}
        </p>
      </div>
      )}
    </div>
  );
}

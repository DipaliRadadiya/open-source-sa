import { useMemo } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { SearchX, CircleAlert, ListX } from "lucide-react";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { KillProcessButton } from "@/components/dashboard/kill-process-button";

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
        {/* Foreground, not muted: the command is the row's identity. */}
        <span
          tabIndex={0}
          className="block w-full truncate font-mono text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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
 * `limit` renders the same table with fewer rows for the collapsed dashboard
 * card. Only the summary footer and scroll cap are full-view only; the stop
 * button stays in the preview.
 */
export function ProcessTable({
  data,
  query = "",
  failed = false,
  // How many processes the box is running; the rows are only the heaviest `limit`.
  total = null,
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
      meta: { className: "w-full min-w-36 max-w-0 @xl/procs:min-w-64" },
      cell: CommandCell,
    },
    {
      id: "pid",
      // pid arrives as number or string; sort it numerically either way.
      accessorFn: (row) => num(row.pid),
      sortingFn: "basic",
      header: t("processes.pid"),
      meta: { className: "hidden w-20 @xl/procs:table-cell" },
      cell: PidCell,
    },
    {
      accessorKey: "user",
      header: t("processes.user"),
      meta: { className: "hidden w-28 max-w-28 truncate @5xl/procs:table-cell" },
      cell: UserCell,
    },
    {
      id: "cpu",
      accessorFn: (row) => num(row.cpu),
      sortingFn: "basic",
      header: t("processes.cpu"),
      meta: { className: "w-24 @xl/procs:w-32 xl:w-52" },
      cell: CpuCell,
    },
    {
      id: "memory",
      accessorFn: (row) => num(row.memory),
      sortingFn: "basic",
      header: t("processes.memory"),
      meta: { className: "hidden w-32 xl:w-52 @xl/procs:table-cell" },
      cell: MemoryCell,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("processes.actions")}</span>,
      enableSorting: false,
      // 64px = the 32px button plus the cell's own px-4 either side.
      meta: { className: "w-16 text-right" },
      cell: ActionsCell,
    },
  ];

  // `failed` is the request (non-2xx or a schema rejection); an empty `data`
  // is a 200 with nothing in it. Each state describes only itself.
  if (failed) {
    return (
      <EmptyState
        compact
        icon={CircleAlert}
        title={t("processes.unavailable")}
        description={t("processes.unavailableDetail")}
      />
    );
  }

  if (rows.length === 0) {
    /*
     * Compact: the card already has a title. The endpoint returns only the top
     * processes by CPU, so an empty response is the server reporting none.
     */
    return query ? (
      <EmptyState compact icon={SearchX} title={t("processes.noMatch")} />
    ) : (
      <EmptyState
        compact
        icon={ListX}
        title={t("processes.empty")}
        description={t("processes.emptyDetail")}
      />
    );
  }

  return (
    // Narrow cards drop PID, user and memory rather than scroll the Stop button off screen.
    <div className="space-y-3 @container/procs">
      {/* Fixed-height scroll area keeps the page short no matter how many
          processes the server reports. */}
      <div
        className={cn(
          "overflow-auto rounded-xl border [scrollbar-gutter:stable] @3xl/procs:[&_table]:min-w-[48rem] [&>div]:rounded-none [&>div]:border-0",
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
          defaultSorting={[{ id: "cpu", desc: true }]}
        />
      </div>
      {limit ? null : (
      <div className="flex flex-wrap items-center justify-between gap-2">
        {/* The API sends only the heaviest processes by CPU (capped by a server
            setting, 25 by default), so the count describes the list, not the
            machine. "Showing x of y" appears only while a search narrows it. */}
        <p className="text-sm text-muted-foreground">
          {query.trim()
            ? t("processes.showing", { shown: filtered.length, total: data.length })
            : null}
        </p>
        <p className="text-sm tabular-nums text-muted-foreground">
          {/* "Top 25 of 160" once the server reports the total. */}
          {t(total != null && total > data.length ? "processes.summaryOfTotal" : "processes.summary", {
            count: data.length,
            total,
            cpu: format.number(data.reduce((sum, p) => sum + num(p.cpu), 0), { maximumFractionDigits: 1 }),
            memory: format.number(data.reduce((sum, p) => sum + num(p.memory), 0), { maximumFractionDigits: 1 }),
          })}
        </p>
      </div>
      )}
    </div>
  );
}

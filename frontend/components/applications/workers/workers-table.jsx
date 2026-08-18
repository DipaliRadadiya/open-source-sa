"use client";

import { useTranslations } from "next-intl";
import { FolderOpen } from "lucide-react";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { WorkerStatusBadge } from "@/components/applications/workers/worker-status-badge";
import { WorkerActions } from "@/components/applications/workers/worker-actions";
import { WorkerRowActions } from "@/components/applications/workers/worker-row-actions";

// Cells are module-level so flexRender's identity stays stable across the busy
// map's re-renders — an inline cell function gets a fresh type every render and
// React remounts the whole cell, closing any open menu inside it.

function NameCell({ row }) {
  const t = useTranslations("applications.workers");
  const worker = row.original;
  // One quiet metadata line instead of a badge + a separate line — kind and
  // added-time are both static descriptive facts, not states worth a pill.
  const meta = worker.created_at_human
    ? `${worker.kind_title} · ${t("addedAgo", { time: worker.created_at_human })}`
    : worker.kind_title;
  return (
    <div className="min-w-0">
      <p className="font-medium">{worker.name}</p>
      {worker.created_at ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <p
              tabIndex={0}
              className="mt-0.5 w-fit text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {meta}
            </p>
          </TooltipTrigger>
          <TooltipContent>{worker.created_at}</TooltipContent>
        </Tooltip>
      ) : (
        <p className="mt-0.5 text-xs text-muted-foreground">{meta}</p>
      )}
    </div>
  );
}

function CommandCell({ row }) {
  const t = useTranslations("applications.workers");
  const worker = row.original;
  return (
    <div className="min-w-0">
      <div className="flex items-center gap-1">
        <Tooltip>
          <TooltipTrigger asChild>
            <span
              tabIndex={0}
              className="block max-w-xs truncate rounded font-mono text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {worker.command}
            </span>
          </TooltipTrigger>
          <TooltipContent className="max-w-sm font-mono text-xs break-all">
            {worker.command}
          </TooltipContent>
        </Tooltip>
        <CopyButton value={worker.command} label={t("form.command")} className="size-6" />
      </div>
      {/* Directory defaults to the site's document root — only worth a line
          when it's been pointed somewhere else, which is otherwise invisible
          outside Edit. */}
      {worker.directory ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span
              tabIndex={0}
              className="mt-1 flex max-w-xs items-center gap-1 truncate text-[11px] text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              <FolderOpen className="size-3 shrink-0" />
              <span className="truncate font-mono">{worker.directory}</span>
            </span>
          </TooltipTrigger>
          <TooltipContent className="max-w-sm font-mono text-xs break-all">
            {t("customDirectoryTooltip", { path: worker.directory })}
          </TooltipContent>
        </Tooltip>
      ) : null}
    </div>
  );
}

function StatusCell({ row, table }) {
  return (
    <WorkerStatusBadge
      worker={row.original}
      busyAction={table.options.meta.busy[row.original.id]}
    />
  );
}

function ActionsCell({ row, table }) {
  const { appId, canManage, setRowBusy, onWorkerUpdated } = table.options.meta;
  return (
    <WorkerActions
      worker={row.original}
      appId={appId}
      canManage={canManage}
      onBusyChange={(action) => setRowBusy(row.original.id, action)}
      onUpdated={onWorkerUpdated}
    />
  );
}

function RowMenuCell({ row, table }) {
  const { appId, presets, canManage, workers } = table.options.meta;
  return (
    <WorkerRowActions
      worker={row.original}
      appId={appId}
      presets={presets}
      workers={workers}
      canManage={canManage}
    />
  );
}

export function WorkersTable({ data, appId, presets = [], canManage = false, busy, setRowBusy, onWorkerUpdated }) {
  const t = useTranslations("applications.workers");

  const columns = [
    { accessorKey: "name", header: t("columns.name"), cell: NameCell },
    { accessorKey: "command", header: t("columns.command"), cell: CommandCell },
    { accessorKey: "state", header: t("columns.status"), cell: StatusCell },
    {
      id: "actions",
      header: () => <span className="block text-right">{t("columns.actions")}</span>,
      meta: { className: "text-right" },
      cell: ActionsCell,
    },
    {
      id: "menu",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: RowMenuCell,
    },
  ];

  return (
    <DataTable
      columns={columns}
      data={data}
      meta={{ appId, presets, canManage, busy, setRowBusy, onWorkerUpdated, workers: data }}
      emptyMessage={t("empty.title")}
      rowClassName={(worker) =>
        cn(
          // Rows carry 2-3 lines per cell now (name+meta, command+directory) —
          // py-2 was sized for a single line and reads cramped against that.
          "[&_td]:py-3",
          worker.state === "degraded" && "bg-warning/5 hover:bg-warning/10",
        )
      }
    />
  );
}

"use client";

import { useTranslations } from "next-intl";
import { FolderOpen } from "lucide-react";
import { cn } from "@/lib/utils";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { WorkerStatusBadge } from "@/components/applications/workers/worker-status-badge";
import { WorkerActions } from "@/components/applications/workers/worker-actions";
import { WorkerRowActions } from "@/components/applications/workers/worker-row-actions";
import { CardList, CardListItem } from "@/components/data-table/card-list";

function WorkerMeta({ worker, t }) {
  const meta = worker.created_at_human
    ? `${worker.kind_title} · ${t("addedAgo", { time: worker.created_at_human })}`
    : worker.kind_title;

  if (!worker.created_at) {
    return <p className="mt-0.5 truncate text-xs text-muted-foreground">{meta}</p>;
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <p
          tabIndex={0}
          className="mt-0.5 w-fit max-w-full truncate text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {meta}
        </p>
      </TooltipTrigger>
      <TooltipContent>{worker.created_at}</TooltipContent>
    </Tooltip>
  );
}

export function WorkersCards({ data, appId, presets = [], canManage, busy, setRowBusy, onWorkerUpdated }) {
  const t = useTranslations("applications.workers");

  return (
    <CardList>
      {data.map((worker) => (
        <CardListItem
          key={worker.id}
          className={cn(worker.state === "degraded" && "border-warning/30 bg-warning/5")}
        >
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate font-medium">{worker.name}</p>
              <WorkerMeta worker={worker} t={t} />
            </div>
            <WorkerStatusBadge worker={worker} busyAction={busy[worker.id]} />
          </div>

          <div className="mt-3 flex items-center gap-1 rounded border bg-muted/30 pr-1 pl-2">
            <p className="min-w-0 flex-1 truncate py-1.5 font-mono text-xs text-muted-foreground">
              {worker.command}
            </p>
            <CopyButton value={worker.command} label={t("form.command")} className="size-6" />
          </div>
          {worker.directory ? (
            <p className="mt-1.5 flex items-center gap-1 truncate text-[11px] text-muted-foreground">
              <FolderOpen className="size-3 shrink-0" />
              <span className="truncate font-mono">{worker.directory}</span>
            </p>
          ) : null}

          <div className="mt-3 flex items-center justify-between border-t pt-3">
            <WorkerActions
              worker={worker}
              appId={appId}
              canManage={canManage}
              reserveSlots={false}
              onBusyChange={(action) => setRowBusy(worker.id, action)}
              onUpdated={onWorkerUpdated}
            />
            <WorkerRowActions worker={worker} appId={appId} presets={presets} workers={data} canManage={canManage} />
          </div>
        </CardListItem>
      ))}
    </CardList>
  );
}

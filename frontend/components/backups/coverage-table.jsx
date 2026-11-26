import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { wrappableTimezoneLabel } from "@/lib/format/timezone";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { History, MoreHorizontal, PlayCircle, Settings2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { ActionIcon } from "@/components/ui/action-icon";
import { DomainText } from "@/components/ui/domain-text";
import { COVERAGE_STATE } from "@/components/backups/status-meta";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { HoverHint } from "@/components/ui/hover-hint";
import { COVERAGE_WIDTHS } from "@/components/backups/coverage-widths";

// Cells at module level: inline cells remount every render.

function SiteCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { application } = row.original;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
        <HoverHint content={application.name}><Link
          prefetch={false}
          href={`/applications/${application.id}/backups`}
          className="max-w-full shrink-0 truncate font-medium underline-offset-4 hover:underline"
        >
          {application.name}
        </Link></HoverHint>
        {application.is_staging ? (
          <Badge variant="secondary" className="shrink-0 text-xs">
            {t("staging")}
          </Badge>
        ) : null}
        {row.original.state === "paused" || row.original.state === "unprotected" ? null : (
          <span className="shrink-0">
            <StatusCell row={row} />
          </span>
        )}
      </div>
      <DomainText domain={application.domain} className="text-xs text-muted-foreground" />
    </div>
  );
}

export function StatusCell({ row }) {
  const t = useTranslations("backups.coverage");
  const meta = COVERAGE_STATE[row.original.state];
  const Icon = meta.icon;

  return (
    <Badge variant={meta.variant} className="gap-1.5 font-normal">
      <Icon className="size-3" />
      {t(`status.${row.original.state}`)}
    </Badge>
  );
}

function Placeholder({ children }) {
  return <span className="text-sm text-muted-foreground">{children}</span>;
}

function TypeCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.type")}</Placeholder>;
  return <span className="block truncate text-sm">{target.type_title ?? target.type}</span>;
}

export function StorageCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  return (
    <span className="block truncate text-sm text-muted-foreground">
      {target?.storage_destination_name ?? t("placeholders.storage")}
    </span>
  );
}

export function ScheduleCell({ row, options }) {
  const t = useTranslations("backups.coverage");
  const tb = useTranslations("backups");
  const format = useFormatter();
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.schedule")}</Placeholder>;

  const when = scheduleWhen(target, options, format);
  const time = when?.minute ? t("minutePast", { minute: when.minute }) : (when?.time ?? null);

  const paused = row.original.state === "paused";

  return (
    <div className="min-w-0">
      <p className={cn("truncate text-sm", paused && "font-medium text-warning")}>
        {frequencyLabel(target, (frequency) => tb("pausedFrequency", { frequency }))}
      </p>
      {time ? <p className="truncate text-xs tabular-nums">{time}</p> : null}
      <p className="truncate text-xs tabular-nums text-muted-foreground">
        {t("keeps", { count: target.retention_count })}
      </p>
    </div>
  );
}

export function RunsCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target, lastBackup } = row.original;
  if (!target) return <Placeholder>{t("placeholders.lastRun")}</Placeholder>;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-1.5">
        {lastBackup ? (
          <BackupStatusDot
            status={lastBackup.status}
            label={lastBackup.status_title ?? lastBackup.status}
          />
        ) : null}
        {/* Fall back to the backup's own time: a crashed run doesn't write last_run_at. */}
        <span className="text-sm whitespace-normal tabular-nums">
          {lastBackup?.created_at_human ?? target.last_backup_at_human ?? t("neverRunShort")}
        </span>
      </div>
      {target.is_due ? (
        <p className="text-xs whitespace-normal text-muted-foreground">{t("runsShortly")}</p>
      ) : target.next_run_at_human ? (
        <p className="text-xs whitespace-normal tabular-nums text-muted-foreground">
          {t("nextRun", { when: target.next_run_at_human })}
        </p>
      ) : null}
    </div>
  );
}

function BackupStatusDot({ status, label }) {
  const tone =
    status === "verified"
      ? "bg-success"
      : status === "failed"
        ? "bg-destructive"
        : "bg-primary";
  return (
    <HoverHint content={label}><span
      className={cn("size-1.5 shrink-0 rounded-full", tone)}
      role="img"
      aria-label={label}
    /></HoverHint>
  );
}

function ActionsCell({ row, table }) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const ta = useTranslations("backups.application");
  const { canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null } = table.options.meta;
  const { application, target, state } = row.original;

  if (state === "unprotected") {
    return (
      <div className="flex items-center justify-end gap-1">
        <Button
          size="sm"
          variant="outline"
          disabled={!canManage}
          disabledReason={canManage ? null : DENIED}
          onClick={() => onSetUp(application.id)}
        >
          <ShieldCheck className="size-4" />
          {t("setUpShort")}
        </Button>
        <span className="size-8 shrink-0" aria-hidden />
      </div>
    );
  }

  return (
    <div className="flex items-center justify-end gap-1">
      <ReasonTooltip
        reason={
          !canManage
            ? DENIED
            : restoringId === application.id
              ? ta("restoreRunning")
              : !target && !busyIds.includes(application.id)
                ? tc("needsBackupTarget")
                : null
        }
      >
        <Button
          size="sm"
          variant="outline"
          onClick={() => onBackUpNow(application.id, application.name)}
          disabled={!canManage || busyIds.includes(application.id) || !target || restoringId === application.id}
        >
          <ActionIcon icon={PlayCircle} pending={busyIds.includes(application.id)} className="size-4" />
          {t("runBackup")}
        </Button>
      </ReasonTooltip>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8" aria-label={t("moreActions")}>
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link href={`/backups/history?application=${application.id}`}>
              <History className="size-4" />
              {t("viewBackups")}
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link href={`/applications/${application.id}/backups`}>
              <Settings2 className="size-4" />
              {t("manage")}
            </Link>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

export { COVERAGE_WIDTHS };

export function CoverageTable({ rows, options = null, canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null, scheduleTimezone = null, bare = false }) {
  const t = useTranslations("backups.coverage");

  const columns = [
    {
      accessorKey: "application.name",
      header: t("columns.site"),
      meta: { className: "max-w-0" },
      cell: SiteCell,
    },
    { id: "type", header: wrapping(t("columns.typeShort")), meta: { className: COVERAGE_WIDTHS.kind }, cell: TypeCell },
    { id: "schedule", header: wrapping(scheduleTimezone ? t("columns.scheduleIn", { timezone: wrappableTimezoneLabel(scheduleTimezone) }) : t("columns.schedule")), meta: { className: COVERAGE_WIDTHS.schedule }, cell: (ctx) => <ScheduleCell {...ctx} options={options} /> },
    { id: "storage", header: wrapping(t("columns.storage")), meta: { className: COVERAGE_WIDTHS.storage }, cell: StorageCell },
    { id: "runs", header: wrapping(t("columns.lastRun")), meta: { className: COVERAGE_WIDTHS.runs }, cell: RunsCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      meta: { className: `${COVERAGE_WIDTHS.actions} text-right` },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      bare={bare}
      fixedLayout
      columns={columns}
      data={rows}
      emptyMessage={t("noMatches")}
      spanCells={{
        columns: ["type", "schedule", "storage", "runs"],
        render: (row) => (row.target ? null : <NotSetUp />),
      }}
      meta={{ canManage, onSetUp, onBackUpNow, busyIds, restoringId }}
    />
  );
}

const wrapping = (label) => function WrappingHeader() {
  return <span className="whitespace-nowrap">{label}</span>;
};

function NotSetUp() {
  const t = useTranslations("backups.coverage");
  return <span className="text-sm whitespace-normal text-muted-foreground/80">{t("notSetUpLine")}</span>;
}

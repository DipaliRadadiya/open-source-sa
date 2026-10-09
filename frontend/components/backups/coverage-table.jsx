import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { History, MoreHorizontal, PlayCircle, Settings2, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
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

// Cells are module-level components: flexRender treats a cell function's identity
// as the component type, so one defined in render remounts every time.

// Failing first, as it needs attention today; unprotected last, since its
// placeholders keep it legible anywhere in the list.
const STATE_ORDER = { failing: 0, protected: 1, paused: 2, unknown: 3, unprotected: 4 };

export function sortCoverage(rows) {
  return [...rows].sort(
    (a, b) =>
      STATE_ORDER[a.state] - STATE_ORDER[b.state] ||
      a.application.name.localeCompare(b.application.name),
  );
}

function SiteCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { application } = row.original;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-2">
        <Link
          prefetch={false}
          href={`/applications/${application.id}/backups`}
          title={application.name}
          className="truncate font-medium underline-offset-4 hover:underline"
        >
          {application.name}
        </Link>
        {application.is_staging ? (
          <Badge variant="secondary" className="shrink-0 text-xs">
            {t("staging")}
          </Badge>
        ) : null}
        {/* Status beside the name, at every width. Not where the row already says it
            (Krishna, 8 Oct): "Manual only" is in the Schedule column, and "None" is the
            "Not set up" line across the row. */}
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

function StatusCell({ row }) {
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

/** A site with no schedule states that in every column, so it does not look like missing data. */
function Placeholder({ children }) {
  return <span className="text-sm text-muted-foreground">{children}</span>;
}

function TypeCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.type")}</Placeholder>;
  return <span className="block truncate text-sm">{target.type_title ?? target.type}</span>;
}

function StorageCell({ row }) {
  const t = useTranslations("backups.coverage");
  const { target } = row.original;
  return (
    <span className="block truncate text-sm text-muted-foreground">
      {target?.storage_destination_name ?? t("placeholders.storage")}
    </span>
  );
}

function ScheduleCell({ row, options }) {
  const t = useTranslations("backups.coverage");
  const tb = useTranslations("backups");
  const format = useFormatter();
  const { target } = row.original;
  if (!target) return <Placeholder>{t("placeholders.schedule")}</Placeholder>;

  // The hour on its own line: the column is too narrow for "Daily · 2:00 AM".
  // A manual target has no hour and gets no line.
  const when = scheduleWhen(target, options, format);
  const time = when?.minute ? t("minutePast", { minute: when.minute }) : (when?.time ?? null);

  // Amber when paused: this is the row's only "Manual only", since it has no badge.
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

/** `next_run_at_human` comes from the backend, never computed here. */
function RunsCell({ row }) {
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
        {/* Falls back to the backup's own timestamp, not "Never": the runner's
            crash path does not write `last_run_at`. */}
        <span className="truncate text-sm tabular-nums">
          {target.last_run_at_human ?? lastBackup?.created_at_human ?? t("neverRunShort")}
        </span>
      </div>
      {/* `is_due` outranks the timestamp: a new target's first backup runs on
          the next tick while `next_run_at` still names tomorrow. */}
      {target.is_due ? (
        <p className="truncate text-xs text-muted-foreground">{t("runsShortly")}</p>
      ) : target.next_run_at_human ? (
        <p className="truncate text-xs tabular-nums text-muted-foreground">
          {t("nextRun", { when: target.next_run_at_human })}
        </p>
      ) : null}
    </div>
  );
}

/** Outcome of the last run: coloured and named, so it is not colour-only. */
function BackupStatusDot({ status, label }) {
  const tone =
    status === "verified"
      ? "bg-success"
      : status === "failed"
        ? "bg-destructive"
        : "bg-primary";
  return (
    <span
      className={cn("size-1.5 shrink-0 rounded-full", tone)}
      role="img"
      aria-label={label}
      title={label}
    />
  );
}

/** One shape of action per state, so the column has a single rhythm. */
function ActionsCell({ row, table }) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const th = useTranslations("backups.history");
  const ta = useTranslations("backups.application");
  const { canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null } = table.options.meta;
  const { application, target, state } = row.original;

  // Viewers see the actions too, disabled with the reason.
  if (state === "unprotected") {
    return (
      <div className="flex items-center justify-end gap-1">
        <Button
          size="sm"
          variant="outline"
          disabled={!canManage}
          disabledReason={canManage ? null : th("noPermission")}
          onClick={() => onSetUp(application.id)}
        >
          <ShieldCheck className="size-4" />
          {t("setUpShort")}
        </Button>
        {/* Reserves the ⋯ space so all rows share one right edge. */}
        <span className="size-8 shrink-0" aria-hidden />
      </div>
    );
  }

  // One button plus a menu for the occasional actions.
  return (
    <div className="flex items-center justify-end gap-1">
      <ReasonTooltip
        reason={
          !canManage
            ? th("noPermission")
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

export function CoverageTable({ rows, options = null, canManage, onSetUp, onBackUpNow, busyIds = [], restoringId = null, bare = false }) {
  const t = useTranslations("backups.coverage");

  const columns = [
    {
      accessorKey: "application.name",
      header: t("columns.site"),
      // max-w-0 lets the name and domain truncate; without it their full width
      // set the column and the table needed 1,100px in French.
      meta: { className: "w-[28%] max-w-0" },
      cell: SiteCell,
    },
    // Headers may wrap so long translations don't widen the columns.
    // Own columns again (Krishna, 8 Oct: the stacked "Backup type / Storage" header read
    // as cramped); dropping the Status column made the room.
    // "Type", not "Backup type": on this page it can only mean that, and the long
    // form wrapped to two lines at 1280.
    { id: "type", header: wrapping(t("columns.typeShort")), meta: { className: "w-28" }, cell: TypeCell },
    { id: "schedule", header: wrapping(t("columns.schedule")), meta: { className: "w-32" }, cell: (ctx) => <ScheduleCell {...ctx} options={options} /> },
    { id: "storage", header: wrapping(t("columns.storage")), meta: { className: "w-32" }, cell: StorageCell },
    { id: "runs", header: wrapping(t("columns.lastRun")), meta: { className: "w-36" }, cell: RunsCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      meta: { className: "w-44 text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      bare={bare}
      columns={columns}
      data={rows}
      emptyMessage={t("noMatches")}
      // A site with no target gets one sentence across the four columns.
      spanCells={{
        columns: ["type", "schedule", "storage", "runs"],
        render: (row) => (row.target ? null : <NotSetUp />),
      }}
      meta={{ canManage, onSetUp, onBackUpNow, busyIds, restoringId }}
    />
  );
}

const wrapping = (label) => function WrappingHeader() {
  return <span className="whitespace-normal">{label}</span>;
};

function NotSetUp() {
  const t = useTranslations("backups.coverage");
  // Wraps, or this sentence sets the width of the columns it spans and pushes
  // the Run button off-screen.
  return <span className="text-sm whitespace-normal text-muted-foreground/80">{t("notSetUpLine")}</span>;
}

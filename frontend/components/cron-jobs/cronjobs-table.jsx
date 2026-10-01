import { useFormatter, useNow, useTranslations } from "next-intl";
import { serverTimeToEpoch } from "@/lib/cron-jobs/schedule";
import { useScheduleText } from "@/components/cron-jobs/schedule-preview";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { DataTable } from "@/components/ui/data-table";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { CronjobActiveSwitch } from "@/components/cron-jobs/cronjob-active-switch";
import { CronjobRowActions } from "@/components/cron-jobs/cronjob-row-actions";
import { CronjobsCards } from "@/components/cron-jobs/cronjobs-cards";

// Labels from the API's own preset list; deliberately not a cron-to-prose library.
function scheduleLabel(expression, presets) {
  return presets.find((p) => p.expression && p.expression === expression)?.label ?? null;
}

// Cells at module level: inline cells would remount (losing dialog state) every render.

// Exported so the phone cards show exactly what the table shows.

export function CronjobName({ job }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
      {/* Wraps even inside a word, so a long unbroken name cannot widen the table. */}
      <span className="max-w-56 font-medium whitespace-normal [overflow-wrap:anywhere]">{job.name}</span>
      {/* Paused gets a badge; a switch position alone is easy to miss. */}
      {!job.active ? (
        // Amber like a paused application: nothing is broken, but it must be seen.
        <Badge variant="warning" className="font-normal">
          {t("paused")}
        </Badge>
      ) : null}
    </div>
  );
}

export function CronjobSchedule({ job, presets = [] }) {
  const custom = useScheduleText(job.expression, job.timezone);
  const preset = scheduleLabel(job.expression, presets);
  // A custom schedule gets its sentence: "0 0 2 * *" is not 2 AM daily.
  const label = preset ?? custom?.sentence ?? null;
  // Plain language when nameable; otherwise the expression alone, not "Custom".
  return label ? (
    <div className="flex flex-col gap-0.5">
      {/* A sentence wraps (TableCell defaults to nowrap); short preset names do not. */}
      <span className={preset ? "whitespace-nowrap" : "max-w-44 whitespace-normal"}>{label}</span>
      <span className="font-mono text-xs text-muted-foreground">{job.expression}</span>
    </div>
  ) : (
    <span className="font-mono text-xs">{job.expression}</span>
  );
}

export function CronjobNextRun({ job }) {
  const format = useFormatter();
  const now = useNow({ updateInterval: 15000 });
  const { next_run_at: at } = job;
  // A paused job has no next run.
  if (!at) return <span className="text-muted-foreground">—</span>;

  // Counted down live (`next_run_at_human` is only true at page load); reads "now" until the re-read.
  const epoch = serverTimeToEpoch(at, job.timezone);
  const human = epoch === null ? job.next_run_at_human : format.relativeTime(Math.max(epoch, now.getTime()), now);

  // As the API computed it, in the server's zone; the browser's formatter would convert it.
  return (
    <div className="flex flex-col gap-0.5">
      {/* Server and client render a moment apart ("in 19s" vs "in 18s"); expected. */}
      <span className="whitespace-nowrap" suppressHydrationWarning>{human}</span>
      <span className="whitespace-nowrap text-xs text-muted-foreground">{at}</span>
    </div>
  );
}

export function CronjobRunAs({ job }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="font-mono text-xs">{job.username}</span>
      {/* No linked system_user => an unmanaged OS account like root. */}
      {!job.system_user ? (
        <Badge variant="outline" className="font-normal">
          {t("unmanaged")}
        </Badge>
      ) : null}
    </div>
  );
}

// Headers may wrap, so long translations don't push the ⋯ column off screen.
function Head({ children }) {
  return <span className="block whitespace-normal">{children}</span>;
}

function NameCell({ row }) {
  return <CronjobName job={row.original} />;
}

function ScheduleCell({ row, table }) {
  return <CronjobSchedule job={row.original} presets={table.options.meta.schedulePresets} />;
}

function NextRunCell({ row }) {
  return <CronjobNextRun job={row.original} />;
}

function RunAsCell({ row }) {
  return <CronjobRunAs job={row.original} />;
}

function CommandCell({ row }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* tabIndex: a truncated value must be reachable without a mouse. */}
        <span
          tabIndex={0}
          className="block max-w-40 truncate rounded font-mono text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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

function ActiveCell({ row, table }) {
  return <CronjobActiveSwitch job={row.original} canManage={table.options.meta.canManage} prevPage={table.options.meta.prevPage} />;
}

function ActionsCell({ row, table }) {
  const { schedulePresets, commandPresets, applications, placeholder, timezone, onDuplicate, runAs, prevPage, canManage, canViewLogs } =
    table.options.meta;
  return (
    <CronjobRowActions
      job={row.original}
      schedulePresets={schedulePresets}
      commandPresets={commandPresets}
      applications={applications}
      placeholder={placeholder}
      timezone={timezone}
      onDuplicate={onDuplicate}
      runAs={runAs}
      prevPage={prevPage}
      canManage={canManage}
      canViewLogs={canViewLogs}
    />
  );
}

export function CronjobsTable({
  data,
  canViewLogs = false,
  runAs,
  prevPage = null,
  canManage = false,
  schedulePresets = [],
  commandPresets = [],
  applications = [],
  placeholder,
  timezone,
  onDuplicate,
}) {
  const t = useTranslations("cronJobs");

  const columns = [
    { accessorKey: "name", header: () => <Head>{t("columns.name")}</Head>, cell: NameCell },
    { accessorKey: "expression", header: () => <Head>{t("columns.schedule")}</Head>, cell: ScheduleCell },
    {
      accessorKey: "next_run_at",
      // The timezone is stated once, in the column header it applies to.
      header: () => <Head>{timezone ? t("columns.nextRunIn", { timezone }) : t("columns.nextRun")}</Head>,
      cell: NextRunCell,
    },
    { accessorKey: "username", header: () => <Head>{t("columns.runAs")}</Head>, cell: RunAsCell },
    { accessorKey: "command", header: () => <Head>{t("columns.command")}</Head>, cell: CommandCell },
    { id: "active", header: () => <Head>{t("columns.active")}</Head>, cell: ActiveCell },
    // Always there: a view-only reader still gets the menu, each item saying why it is off.
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: ActionsCell,
    },
  ];

  return (
    <>
      {/* Cards below xl: seven columns need ~950px, more than lg provides. */}
      <div className="xl:hidden">
        <CronjobsCards
          jobs={data}
          canManage={canManage}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          onDuplicate={onDuplicate}
          runAs={runAs}
          prevPage={prevPage}
          canViewLogs={canViewLogs}
        />
      </div>
      <div className="hidden xl:block">
        <DataTable
          columns={columns}
          data={data}
          meta={{
            canManage,
            schedulePresets,
            commandPresets,
            applications,
            placeholder,
            timezone,
            onDuplicate,
            runAs,
            prevPage,
            canViewLogs,
          }}
          emptyMessage={t("empty.title")}
          // De-emphasise text, not controls: a paused job must stay operable.
          rowClassName={(job) => cn(!job.active && "[&_td]:text-muted-foreground")}
        />
      </div>
    </>
  );
}

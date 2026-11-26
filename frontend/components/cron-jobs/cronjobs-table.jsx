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
import { CronjobPhpNote } from "@/components/cron-jobs/cronjob-php-note";
import { CronjobsCards } from "@/components/cron-jobs/cronjobs-cards";
import { CronjobLastRun } from "@/components/cron-jobs/cronjob-last-run";
import { hasRunHistory } from "@/lib/cron-jobs/run-duration";
import { timezoneLabel } from "@/lib/format/timezone";

function scheduleLabel(expression, presets) {
  return presets.find((p) => p.expression && p.expression === expression)?.label ?? null;
}

export function CronjobName({ job }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="max-w-56 font-medium whitespace-normal [overflow-wrap:anywhere]">{job.name}</span>
      {!job.active ? (
        <Badge variant="warning" className="h-auto font-normal whitespace-normal">
          {t("paused")}
        </Badge>
      ) : null}
    </div>
  );
}

export function CronjobSchedule({ job, presets = [] }) {
  const custom = useScheduleText(job.expression, job.timezone);
  const preset = scheduleLabel(job.expression, presets);
  const label = preset ?? custom?.sentence ?? null;
  return label ? (
    <div className="flex flex-col gap-0.5">
      <span className="max-w-44 whitespace-normal">{label}</span>
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
  if (!at) return <span className="text-muted-foreground">—</span>;

  const epoch = serverTimeToEpoch(at, job.timezone);
  const human = epoch === null ? job.next_run_at_human : format.relativeTime(Math.max(epoch, now.getTime()), now);

  return (
    <div className="flex flex-col gap-0.5">
      <span className="whitespace-normal" suppressHydrationWarning>{human}</span>
      <span className="text-xs whitespace-normal text-muted-foreground">{at}</span>
    </div>
  );
}

export function CronjobRunAs({ job }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="font-mono text-xs">{job.username}</span>
      {!job.system_user ? (
        <Badge variant="outline" className="font-normal">
          {t("unmanaged")}
        </Badge>
      ) : null}
    </div>
  );
}

function Head({ children }) {
  return <span className="block whitespace-nowrap">{children}</span>;
}

function NameCell({ row }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="space-y-1">
      <CronjobName job={row.original} />
      <div className="flex flex-wrap items-center gap-x-1.5 text-xs whitespace-normal text-muted-foreground">
        <span>{t("columns.runAs")}</span>
        <CronjobRunAs job={row.original} />
      </div>
    </div>
  );
}

function ScheduleCell({ row, table }) {
  return <CronjobSchedule job={row.original} presets={table.options.meta.schedulePresets} />;
}

function NextRunCell({ row }) {
  return <CronjobNextRun job={row.original} />;
}

function LastRunCell({ row }) {
  return <CronjobLastRun job={row.original} />;
}

function CommandCell({ row, table }) {
  return (
    <div className="max-w-32 space-y-1">
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          tabIndex={0}
          className="block max-w-32 truncate rounded font-mono text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {row.original.command}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-sm font-mono text-xs break-all">
        {row.original.command}
      </TooltipContent>
    </Tooltip>
      <CronjobPhpNote job={row.original} applications={table.options.meta.applications} />
    </div>
  );
}

function ActiveCell({ row, table }) {
  return <CronjobActiveSwitch job={row.original} canManage={table.options.meta.canManage} prevPage={table.options.meta.prevPage} />;
}

function ActionsCell({ row, table }) {
  const { schedulePresets, commandPresets, applications, placeholder, timezone, onDuplicate, runAs, prevPage, canManage, canViewLogs, historySupported } =
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
      historySupported={historySupported}
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
  const historySupported = hasRunHistory(data);

  const columns = [
    { accessorKey: "name", header: () => <Head>{t("columns.name")}</Head>, cell: NameCell },
    { accessorKey: "expression", header: () => <Head>{t("columns.schedule")}</Head>, cell: ScheduleCell },
    {
      accessorKey: "next_run_at",
      header: () => <Head>{timezone ? t("columns.nextRunIn", { timezone: timezoneLabel(timezone) }) : t("columns.nextRun")}</Head>,
      cell: NextRunCell,
    },
    ...(historySupported ? [{ id: "last_run", header: () => <Head>{t("lastRun.column")}</Head>, cell: LastRunCell }] : []),
    { accessorKey: "command", header: () => <Head>{t("columns.command")}</Head>, cell: CommandCell },
    { id: "active", header: () => <Head>{t("columns.active")}</Head>, cell: ActiveCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: ActionsCell,
    },
  ];

  const cardsClass = "@min-[900px]/page:hidden";
  const tableClass = "hidden @min-[900px]/page:block";

  return (
    <div className="@container">
      <div className={cardsClass}>
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
          historySupported={historySupported}
        />
      </div>
      <div className={tableClass}>
        <DataTable
          bare
          roomy
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
            historySupported,
          }}
          emptyMessage={t("empty.title")}
          rowClassName={(job) => cn(!job.active && "[&_td]:text-muted-foreground")}
        />
      </div>
    </div>
  );
}

import { useTranslations } from "next-intl";
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

/**
 * Labels an expression using the API's own preset list ("Daily (midnight)"),
 * falling back to the raw cron string. Deliberately not a cron-to-prose library
 * — one more dependency with its own translations to cover the long tail.
 */
function scheduleLabel(expression, presets) {
  return presets.find((p) => p.expression && p.expression === expression)?.label ?? null;
}

/* ---------------------------------------------------------------------------
 * Cells are module-level components on purpose.
 *
 * flexRender calls `createElement(cellFn)`, so a cell function's identity IS the
 * component type. Defined inline they get a fresh identity on every render, and
 * React unmounts and remounts the whole cell — destroying any state inside it,
 * including an open dialog in the row actions. Harmless until something
 * re-renders the table; a trap the moment anything does.
 *
 * Per-table values reach them through `table.options.meta`.
 * ------------------------------------------------------------------------- */

/* The four value renderers are exported so the phone cards show exactly what the
 * table shows — the same "—" for a paused job's next run, the same unmanaged
 * badge, the same server-timezone timestamp. */

export function CronjobName({ job }) {
  const t = useTranslations("cronJobs");
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="font-medium">{job.name}</span>
      {/* Paused is the exception worth calling out — without a badge the only
          signal is a switch position you have to look for. */}
      {!job.active ? (
        <Badge variant="outline" className="font-normal">
          {t("paused")}
        </Badge>
      ) : null}
    </div>
  );
}

export function CronjobSchedule({ job, presets = [] }) {
  const label = scheduleLabel(job.expression, presets);
  // Plain language leads when we can name the schedule; the expression is the
  // supporting detail. With no match — including when the preset list failed to
  // load — show the expression alone rather than calling it "Custom", which
  // would be a false claim about a preset schedule.
  return label ? (
    <div className="flex flex-col gap-0.5">
      <span className="whitespace-nowrap">{label}</span>
      <span className="font-mono text-xs text-muted-foreground">{job.expression}</span>
    </div>
  ) : (
    <span className="font-mono text-xs">{job.expression}</span>
  );
}

export function CronjobNextRun({ job }) {
  const { next_run_at: at, next_run_at_human: human } = job;
  // A paused job has no next run. A dash says that; "—" beats inventing a time
  // that will never happen.
  if (!at) return <span className="text-muted-foreground">—</span>;

  // Shown exactly as the API computed it, in the server's zone. Running it
  // through the browser's formatter would silently restate it in the reader's
  // timezone while the page subtitle claims the server's.
  return (
    <div className="flex flex-col gap-0.5">
      <span className="whitespace-nowrap">{human}</span>
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
          className="block max-w-xs truncate rounded font-mono text-xs text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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
  return <CronjobActiveSwitch job={row.original} canManage={table.options.meta.canManage} />;
}

function ActionsCell({ row, table }) {
  const { schedulePresets, commandPresets, applications, placeholder, timezone, onDuplicate } =
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
    />
  );
}

export function CronjobsTable({
  data,
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
    { accessorKey: "name", header: t("columns.name"), cell: NameCell },
    { accessorKey: "expression", header: t("columns.schedule"), cell: ScheduleCell },
    { accessorKey: "next_run_at", header: t("columns.nextRun"), cell: NextRunCell },
    { accessorKey: "username", header: t("columns.runAs"), cell: RunAsCell },
    { accessorKey: "command", header: t("columns.command"), cell: CommandCell },
    { id: "active", header: t("columns.active"), cell: ActiveCell },
    ...(canManage
      ? [
          {
            id: "actions",
            header: () => <span className="sr-only">{t("actions.label")}</span>,
            cell: ActionsCell,
          },
        ]
      : []),
  ];

  return (
    <>
      {/* Cards below lg, the table from lg up — seven columns cannot fit a
          phone, and the table quietly hid five of them. */}
      <div className="lg:hidden">
        <CronjobsCards
          jobs={data}
          canManage={canManage}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          onDuplicate={onDuplicate}
        />
      </div>
      <div className="hidden lg:block">
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
          }}
          emptyMessage={t("empty.title")}
          // De-emphasise the row's text, not the controls: the switch and actions
          // must stay at full contrast so a paused job is still operable.
          rowClassName={(job) => cn(!job.active && "[&_td]:text-muted-foreground")}
        />
      </div>
    </>
  );
}

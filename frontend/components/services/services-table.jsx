import Link from "@/components/ui/app-link";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { installHome } from "@/lib/services/install-home";

// Cells are module-level on purpose: flexRender uses the function as the
// component type, so inline cells would remount on every poll and lose state.

function ServiceCell({ row }) {
  const t = useTranslations("services");
  const {
    key,
    label,
    unit,
    state,
    install_reason: installReason,
    install_message: installMessage,
    retryable,
  } = row.original;
  const home = installHome(key);
  const installed = state === "installed";

  // Only what the badge does not already say; the generic `unknown` sentence
  // adds nothing, so the retry link is the useful content there.
  const note = installReason && installReason !== "unknown" ? installMessage : null;

  // `whitespace-normal` is load-bearing: the inherited `whitespace-nowrap`
  // would paint the reason across the other columns.
  let secondLine = null;
  if (installed) {
  // The unit name (what systemctl takes), quietly under the friendly name.
    secondLine = <p className="truncate font-mono text-xs text-muted-foreground">{unit}</p>;
  } else if (note || retryable) {
    secondLine = (
      <p className="text-xs whitespace-normal wrap-anywhere text-muted-foreground">
        {note}
        {retryable ? (
          <>
            {note ? " " : null}
            {/* The retry belongs on the screen that owns this install, so two
                installs cannot run at once. */}
            <Link
              href={home.href}
              className="font-medium whitespace-nowrap text-foreground underline underline-offset-2"
            >
              {t(`state.${home.retryLabel}`)}
            </Link>
          </>
        ) : null}
      </p>
    );
  }

  return (
    <div className="min-w-0">
      <p className="font-medium">{label}</p>
      {secondLine}
    </div>
  );
}

function StatusCell({ row, table }) {
  return (
    <ServiceStatusBadge
      status={row.original.status}
      state={row.original.state}
      busyAction={table.options.meta.busy[row.original.key]}
    />
  );
}

function MemoryCell({ row }) {
  return <Measure value={row.original.usage?.memory_human} />;
}

function CpuCell({ row }) {
  const format = useFormatter();
  const cpu = row.original.usage?.cpu_percent;
  return (
    <Measure
      value={
        cpu == null
          ? null
          : format.number(cpu / 100, {
              style: "percent",
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            })
      }
    />
  );
}

function BootCell({ row, table }) {
  const { canManage, setRowBusy } = table.options.meta;
  return (
    <ServiceBootSwitch
      service={row.original}
      canManage={canManage}
      onBusyChange={(action) => setRowBusy(row.original.key, action)}
    />
  );
}

function ActionsCell({ row, table }) {
  const { canManage, setRowBusy, phpVersions } = table.options.meta;
  // Matched on the key the API itself supplies, never on a unit name parsed out
  // of the version string.
  const php = phpVersions.find((v) => v.service === row.original.key);
  return (
    <ServiceActions
      service={row.original}
      canManage={canManage}
      phpVersion={php?.version}
      onBusyChange={(action) => setRowBusy(row.original.key, action)}
    />
  );
}

// `busy` is owned by the panel so the table and card layouts agree.
export function ServicesTable({ data, phpVersions = [], canManage = false, busy, setRowBusy }) {
  const t = useTranslations("services");

  const columns = [
    {
      accessorKey: "label",
      header: t("columns.service"),
      meta: { className: "w-[34%]" },
      // No lock icon here: the "Always on" cell already carries one.
      cell: ServiceCell,
    },
    {
      accessorKey: "status",
      header: t("columns.status"),
      meta: { className: "w-[13%]" },
      cell: StatusCell,
    },
      // Two plain columns; the header labels them.
    {
      id: "memory",
      header: () => <span className="block text-right">{t("memoryShort")}</span>,
      meta: { className: "w-[10%] text-right" },
      cell: MemoryCell,
    },
    {
      id: "cpu",
      // Padding lives on the column class, which covers header and body cells;
      // repeating it here would misalign the label.
      header: () => <span className="block text-right">{t("cpuShort")}</span>,
      meta: { className: "w-[10%] pr-8 text-right" },
      cell: CpuCell,
    },
    {
      id: "boot",
      // Wraps: "Beim Systemstart starten" is twice the column's width.
      header: () => <span className="block whitespace-normal leading-tight">{t("columns.boot")}</span>,
      meta: { className: "w-[15%]" },
      cell: BootCell,
    },
    {
      id: "actions",
      header: () => <span className="block text-right">{t("columns.actions")}</span>,
      meta: { className: "w-[18%] text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      bare
      columns={columns}
      data={data}
      // With `auto`, a failed install's sentence pushed Actions off screen.
      fixedLayout
      meta={{ busy, setRowBusy, canManage, phpVersions }}
      emptyMessage={t("empty.title")}
      // Failed units get a tinted row; cells are tighter than the default.
      rowClassName={(service) =>
        cn(
          "[&_td]:py-2",
          // Only a real unit that failed. A failed install also reports
          // `status: failed` but already explains itself beside the name.
          service.status === "failed" &&
            service.state === "installed" &&
            "bg-destructive/5 hover:bg-destructive/10",
        )
      }
    />
  );
}

// Null renders as an em dash, never 0: "not measured" and "used none" differ.
function Measure({ value }) {
  if (value == null || value === "") {
    return <span className="text-sm text-muted-foreground">—</span>;
  }
  return <span className="text-sm tabular-nums">{value}</span>;
}

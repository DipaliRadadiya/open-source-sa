"use client";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";

/* ---------------------------------------------------------------------------
 * Cells are module-level components on purpose.
 *
 * flexRender calls `createElement(cellFn)`, so the cell function's identity IS
 * the component type. Defining them inside the render — the obvious way — gave
 * every cell a fresh type on every render, and React responded by unmounting
 * and remounting the whole cell. With the 3s usage poll driving renders, that
 * wiped local state every three seconds: the config-test dialog closed itself,
 * and an action fired just before a poll lost its pending state.
 *
 * Anything a cell needs from the table comes through `table.options.meta`,
 * which can change freely without touching these identities.
 * ------------------------------------------------------------------------- */

function ServiceCell({ row }) {
  return (
    <div className="min-w-0">
      <p className="font-medium">{row.original.label}</p>
      {/* The unit name is what you'd type into systemctl, so it belongs here —
          but quietly, under the name people actually recognise. */}
      <p className="truncate font-mono text-xs text-muted-foreground">{row.original.unit}</p>
    </div>
  );
}

function StatusCell({ row, table }) {
  return (
    <ServiceStatusBadge
      status={row.original.status}
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

// `busy` is owned by the panel, not here: the card layout needs the same map,
// and a service is either working or it isn't — both layouts have to agree.
export function ServicesTable({ data, phpVersions = [], canManage = false, busy, setRowBusy }) {
  const t = useTranslations("services");

  const columns = [
    {
      accessorKey: "label",
      header: t("columns.service"),
      meta: { className: "w-[24%] min-w-48" },
      // No lock icon here: the "Always on" cell already carries one, with the
      // explanation attached. Two locks in one row is the same fact twice.
      cell: ServiceCell,
    },
    {
      accessorKey: "status",
      header: t("columns.status"),
      meta: { className: "w-[14%]" },
      cell: StatusCell,
    },
    // Two plain columns instead of one stacked block. The inline "RAM"/"CPU"
    // labels were repeating what a table header already says, and stacking two
    // labelled figures per cell built a small table inside the table.
    {
      id: "memory",
      header: () => <span className="block text-right">{t("memoryShort")}</span>,
      meta: { className: "w-[12%] text-right" },
      cell: MemoryCell,
    },
    {
      id: "cpu",
      // The padding lives only on the column class, which applies to the header
      // cell AND the body cells. Repeating it on the header's own span indented
      // the label twice and knocked it out of line with its own numbers.
      header: () => <span className="block text-right">{t("cpuShort")}</span>,
      meta: { className: "w-[12%] pr-8 text-right" },
      cell: CpuCell,
    },
    {
      id: "boot",
      header: t("columns.boot"),
      meta: { className: "w-[16%]" },
      cell: BootCell,
    },
    {
      id: "actions",
      header: () => <span className="block text-right">{t("columns.actions")}</span>,
      meta: { className: "text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      columns={columns}
      data={data}
      meta={{ busy, setRowBusy, canManage, phpVersions }}
      emptyMessage={t("empty.title")}
      // A failed unit is why you opened this page — tint the row so it's found
      // without reading every status badge.
      //
      // Tighter cells than the default: six rows of one-line values in
      // full-height cells left the table looking loose and made comparing two
      // services a longer eye-journey than it needs to be.
      rowClassName={(service) =>
        cn(
          "[&_td]:py-2",
          service.status === "failed" && "bg-destructive/5 hover:bg-destructive/10",
        )
      }
    />
  );
}

/**
 * One usage figure. Right-aligned and tabular so the digits line up down the
 * column — these numbers exist to be compared between services, and comparison
 * is a vertical scan.
 *
 * A null renders as an em dash and never 0: "systemd didn't measure it" and
 * "used none" are different facts, and a stopped service showing 0% would read
 * as a running one that happens to be idle.
 */
function Measure({ value }) {
  if (value == null || value === "") {
    return <span className="text-sm text-muted-foreground">—</span>;
  }
  return <span className="text-sm tabular-nums">{value}</span>;
}

"use client";

import Link from "next/link";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { installHome } from "@/lib/services/install-home";

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

  // Only what the badge does not already say. "Installing…" is the badge's job,
  // and the generic failure sentence is both a repeat of "Failed" and an
  // instruction to quote a reference this screen does not show — so on those
  // rows the retry link below is the whole useful content.
  const note = installReason && installReason !== "unknown" ? installMessage : null;

  // While a service is installing, or has failed to install, there is no unit
  // yet — printing one would name a file that does not exist. The reason takes
  // its place, since the row is otherwise inert (no actions, no usage, no logs)
  // and would sit there explaining nothing.
  //
  // `whitespace-normal` is load-bearing: TableCell sets `whitespace-nowrap`,
  // which is inherited, so this sentence rendered on one line and painted
  // straight across the Status, Memory and CPU columns. Fixed column widths do
  // not stop that on their own — a table cell does not clip its overflow.
  let secondLine = null;
  if (installed) {
    // The unit name is what you'd type into systemctl, so it belongs here — but
    // quietly, under the name people actually recognise.
    secondLine = <p className="truncate font-mono text-xs text-muted-foreground">{unit}</p>;
  } else if (note || retryable) {
    secondLine = (
      <p className="text-xs whitespace-normal wrap-anywhere text-muted-foreground">
        {note}
        {retryable ? (
          <>
            {note ? " " : null}
            {/* A link, not a button: the retry belongs on the screen that
                owns this install, and two doors to the same install is how you
                end up running two at once. Which screen that is depends on the
                service — see lib/services/install-home.js. */}
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

// `busy` is owned by the panel, not here: the card layout needs the same map,
// and a service is either working or it isn't — both layouts have to agree.
export function ServicesTable({ data, phpVersions = [], canManage = false, busy, setRowBusy }) {
  const t = useTranslations("services");

  const columns = [
    {
      accessorKey: "label",
      header: t("columns.service"),
      meta: { className: "w-[34%]" },
      // No lock icon here: the "Always on" cell already carries one, with the
      // explanation attached. Two locks in one row is the same fact twice.
      cell: ServiceCell,
    },
    {
      accessorKey: "status",
      header: t("columns.status"),
      meta: { className: "w-[13%]" },
      cell: StatusCell,
    },
    // Two plain columns instead of one stacked block. The inline "RAM"/"CPU"
    // labels were repeating what a table header already says, and stacking two
    // labelled figures per cell built a small table inside the table.
    {
      id: "memory",
      header: () => <span className="block text-right">{t("memoryShort")}</span>,
      meta: { className: "w-[10%] text-right" },
      cell: MemoryCell,
    },
    {
      id: "cpu",
      // The padding lives only on the column class, which applies to the header
      // cell AND the body cells. Repeating it on the header's own span indented
      // the label twice and knocked it out of line with its own numbers.
      header: () => <span className="block text-right">{t("cpuShort")}</span>,
      meta: { className: "w-[10%] pr-8 text-right" },
      cell: CpuCell,
    },
    {
      id: "boot",
      header: t("columns.boot"),
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
      columns={columns}
      data={data}
      // Fixed layout, so the column widths above are obeyed rather than treated
      // as hints. With the browser's default `auto`, a failed install's
      // explanation — a whole sentence — set the first column's width from its
      // longest line, pushed the table past its container and left Actions off
      // the right-hand edge behind a horizontal scrollbar. Fixed makes the
      // sentence wrap inside its column instead, so a row grows downwards when
      // there is something to say and the table never grows sideways.
      fixedLayout
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
          // Only a real unit that failed. An install that failed also reports
          // `status: failed`, but tinting its row says "this broke" about
          // something that never started — and it already explains itself in
          // words beside the name.
          service.status === "failed" &&
            service.state === "installed" &&
            "bg-destructive/5 hover:bg-destructive/10",
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

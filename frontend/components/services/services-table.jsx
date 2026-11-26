import Link from "@/components/ui/app-link";

import { useFormatter, useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { DataTable } from "@/components/ui/data-table";
import { ServiceActions } from "@/components/services/service-actions";
import { ServiceBootSwitch } from "@/components/services/service-boot-switch";
import { ServiceStatusBadge } from "@/components/services/service-status-badge";
import { ServiceVersion } from "@/components/services/service-version";
import { installHome } from "@/lib/services/install-home";
import { SizeText } from "@/components/ui/size-text";

// Cells at module level: inline cells remount on every poll and lose state.

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
    version,
    package_version: packageVersion,
  } = row.original;
  const home = installHome(key);
  const installed = state === "installed";

  const note = installReason && installReason !== "unknown" ? installMessage : null;

  let secondLine = null;
  if (installed) {
    secondLine = (
      <p className="font-mono text-xs whitespace-normal wrap-anywhere text-muted-foreground">
        {unit}
        <ServiceVersion version={version} packageVersion={packageVersion} />
      </p>
    );
  } else if (note || retryable) {
    secondLine = (
      <p className="text-xs whitespace-normal wrap-anywhere text-muted-foreground">
        {note}
        {retryable ? (
          <>
            {note ? " " : null}
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
      <p className="font-medium whitespace-normal wrap-anywhere">{label}</p>
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
    <div className="flex items-center justify-center">
      {!row.original.protected ? <span aria-hidden className="w-6 shrink-0" /> : null}
      <ServiceBootSwitch
        service={row.original}
        canManage={canManage}
        onBusyChange={(action) => setRowBusy(row.original.key, action)}
      />
    </div>
  );
}

function ActionsCell({ row, table }) {
  const { canManage, setRowBusy, phpVersions } = table.options.meta;
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

export function ServicesTable({ data, phpVersions = [], canManage = false, busy, setRowBusy }) {
  const t = useTranslations("services");

  const columns = [
    {
      accessorKey: "label",
      header: t("columns.service"),
      meta: { className: "w-[15%]" },
      cell: ServiceCell,
    },
    {
      accessorKey: "status",
      header: t("columns.status"),
      meta: { className: "w-[22%] text-center" },
      cell: StatusCell,
    },
    {
      id: "memory",
      header: () => <span className="block text-right">{t("memoryShort")}</span>,
      meta: { className: "w-[12%] text-right" },
      cell: MemoryCell,
    },
    {
      id: "cpu",
      header: () => <span className="block text-right">{t("cpuShort")}</span>,
      meta: { className: "w-[10%] text-right" },
      cell: CpuCell,
    },
    {
      id: "boot",
      header: () => <span className="block whitespace-nowrap">{t("columns.boot")}</span>,
      meta: { className: "w-[16%] text-center" },
      cell: BootCell,
    },
    {
      id: "actions",
      header: () => <span className="block text-right">{t("columns.actions")}</span>,
      meta: { className: "w-[25%] text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <DataTable
      bare
      columns={columns}
      data={data}
      fixedLayout
      meta={{ busy, setRowBusy, canManage, phpVersions }}
      emptyMessage={t("empty.title")}
      rowClassName={(service) =>
        cn(
          "[&_td]:py-2",
          service.status === "failed" &&
            service.state === "installed" &&
            "bg-destructive/5 hover:bg-destructive/10",
        )
      }
    />
  );
}

function Measure({ value }) {
  if (value == null || value === "") {
    return <span className="text-sm text-muted-foreground">—</span>;
  }
  return <span className="text-sm tabular-nums"><SizeText value={value} /></span>;
}

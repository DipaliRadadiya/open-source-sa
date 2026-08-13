"use client";

import { useMemo, useState } from "react";
import { SearchX, Server, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { AppsCell } from "@/components/system-users/apps-cell";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";
import { SystemUsersCards } from "@/components/system-users/system-users-cards";

/* ---------------------------------------------------------------------------
 * Cells are module-level components on purpose.
 *
 * flexRender calls `createElement(cellFn)`, so a cell function's identity IS the
 * component type. Defined inline they get a new identity on every render — and
 * the search box lives in this same component, so every keystroke was
 * unmounting and remounting every cell in the table.
 *
 * `canManage` reaches them through `table.options.meta`.
 * ------------------------------------------------------------------------- */

function UsernameCell({ row }) {
  const t = useTranslations("systemUsers");
  return (
    <div className="flex items-center gap-2">
      <span className="font-medium">{row.original.username}</span>
      {!row.original.password ? (
        <Badge variant="warning" className="font-normal">
          {t("noPassword")}
        </Badge>
      ) : null}
    </div>
  );
}

function HomeCell({ row }) {
  return (
    <span className="font-mono text-xs text-muted-foreground">
      {row.original.home_path}
    </span>
  );
}

function ShellCell({ row, table }) {
  return (
    <ShellSelect
      user={row.original}
      shells={table.options.meta.shells}
      canManage={table.options.meta.canManage}
    />
  );
}

function SudoCell({ row, table }) {
  return (
    <AccessSwitch user={row.original} field="sudo" canManage={table.options.meta.canManage} />
  );
}

function SshCell({ row, table }) {
  return (
    <AccessSwitch user={row.original} field="ssh" canManage={table.options.meta.canManage} />
  );
}

function ApplicationsCell({ row }) {
  return <AppsCell user={row.original} />;
}

function CreatedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human}
    </span>
  );
}

function RowActionsCell({ row }) {
  return <SystemUserRowActions user={row.original} />;
}

export function SystemUsersTable({ data, shells = [], canManage = false }) {
  const t = useTranslations("systemUsers");
  const [query, setQuery] = useState("");
  const [createOpen, setCreateOpen] = useState(false);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return data;
    return data.filter((u) => u.username.toLowerCase().includes(q));
  }, [data, query]);

  const columns = [
    { accessorKey: "username", header: t("columns.username"), cell: UsernameCell },
    { accessorKey: "home_path", header: t("columns.home"), cell: HomeCell },
    { accessorKey: "shell", header: t("columns.shell"), cell: ShellCell },
    { id: "sudo", header: t("sudo"), cell: SudoCell },
    { id: "ssh", header: t("ssh"), cell: SshCell },
    { id: "applications", header: t("columns.applications"), cell: ApplicationsCell },
    { accessorKey: "created_at_human", header: t("columns.created"), cell: CreatedCell },
    ...(canManage
      ? [
          {
            id: "actions",
            header: () => <span className="sr-only">{t("actions.label")}</span>,
            cell: RowActionsCell,
          },
        ]
      : []),
  ];

  const isFiltered = query.trim().length > 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <LocalSearchInput
          value={query}
          onChange={setQuery}
          placeholder={t("searchPlaceholder")}
        />
        <div className="flex flex-wrap items-center gap-2">
          <RefreshButton />
          <ReasonTooltip reason={canManage ? null : t("noPermission")}>
            <Button disabled={!canManage} onClick={() => setCreateOpen(true)}>
              <Plus className="size-4" />
              {t("addUser")}
            </Button>
          </ReasonTooltip>
        </div>
      </div>

      {filtered.length === 0 ? (
        isFiltered ? (
          <EmptyState
            icon={SearchX}
            title={t("empty.filteredTitle")}
            description={t("empty.filteredDesc")}
            action={
              <Button variant="outline" onClick={() => setQuery("")}>
                {t("clearSearch")}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Server}
            title={t("empty.title")}
            description={t("empty.desc")}
            action={
              // Even on the empty state: "no users yet" plus a disabled button
              // explains why you can't fix it. An empty page with no action at
              // all just looks unfinished.
              <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                <Button disabled={!canManage} onClick={() => setCreateOpen(true)}>
                  <Plus className="size-4" />
                  {t("addUser")}
                </Button>
              </ReasonTooltip>
            }
          />
        )
      ) : (
        <>
          {/* Cards below lg, the table from lg up. */}
          <div className="lg:hidden">
            <SystemUsersCards users={filtered} shells={shells} canManage={canManage} />
          </div>
          <div className="hidden lg:block">
            <DataTable columns={columns} data={filtered} meta={{ canManage, shells }} />
          </div>
        </>
      )}

      {canManage ? (
        <CreateSystemUserDialog open={createOpen} onOpenChange={setCreateOpen} />
      ) : null}
    </div>
  );
}

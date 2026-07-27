"use client";

import { useMemo, useState } from "react";
import { SearchX, Server, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { AccessSwitch } from "@/components/system-users/access-switch";
import { ShellSelect } from "@/components/system-users/shell-select";
import { AppsCell } from "@/components/system-users/apps-cell";
import { SystemUserRowActions } from "@/components/system-users/system-user-row-actions";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";

export function SystemUsersTable({ data, canManage = false }) {
  const t = useTranslations("systemUsers");
  const [query, setQuery] = useState("");
  const [createOpen, setCreateOpen] = useState(false);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return data;
    return data.filter((u) => u.username.toLowerCase().includes(q));
  }, [data, query]);

  const columns = [
    {
      accessorKey: "username",
      header: t("columns.username"),
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <span className="font-medium">{row.original.username}</span>
          {!row.original.password ? (
            <Badge variant="warning" className="font-normal">
              {t("noPassword")}
            </Badge>
          ) : null}
        </div>
      ),
    },
    {
      accessorKey: "home_path",
      header: t("columns.home"),
      cell: ({ row }) => (
        <span className="font-mono text-xs text-muted-foreground">
          {row.original.home_path}
        </span>
      ),
    },
    {
      accessorKey: "shell",
      header: t("columns.shell"),
      cell: ({ row }) => (
        <ShellSelect user={row.original} canManage={canManage} />
      ),
    },
    {
      id: "sudo",
      header: t("sudo"),
      cell: ({ row }) => (
        <AccessSwitch user={row.original} field="sudo" canManage={canManage} />
      ),
    },
    {
      id: "ssh",
      header: t("ssh"),
      cell: ({ row }) => (
        <AccessSwitch user={row.original} field="ssh" canManage={canManage} />
      ),
    },
    {
      id: "applications",
      header: t("columns.applications"),
      cell: ({ row }) => <AppsCell user={row.original} />,
    },
    {
      accessorKey: "created_at_human",
      header: t("columns.created"),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-muted-foreground">
          {row.original.created_at_human}
        </span>
      ),
    },
    ...(canManage
      ? [
          {
            id: "actions",
            header: () => <span className="sr-only">{t("actions.label")}</span>,
            cell: ({ row }) => <SystemUserRowActions user={row.original} />,
          },
        ]
      : []),
  ];

  const isFiltered = query.trim().length > 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <LocalSearchInput
          value={query}
          onChange={setQuery}
          placeholder={t("searchPlaceholder")}
        />
        <div className="flex items-center gap-2">
          <RefreshButton />
          {canManage ? (
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="size-4" />
              {t("addUser")}
            </Button>
          ) : null}
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
              canManage ? (
                <Button onClick={() => setCreateOpen(true)}>
                  <Plus className="size-4" />
                  {t("addUser")}
                </Button>
              ) : undefined
            }
          />
        )
      ) : (
        <DataTable columns={columns} data={filtered} />
      )}

      {canManage ? (
        <CreateSystemUserDialog open={createOpen} onOpenChange={setCreateOpen} />
      ) : null}
    </div>
  );
}

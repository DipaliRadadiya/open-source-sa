"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { Plus, SearchX, Shield } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { SyncPermissionsButton } from "@/components/admin/roles/sync-permissions-button";
import { RoleRowActions } from "@/components/admin/roles/role-row-actions";

function grantedCount(role) {
  return (role.permissions ?? []).filter(
    (p) => p.permissions?.view || p.permissions?.manage,
  ).length;
}

export function RolesTable({ data }) {
  const t = useTranslations("roles");
  // The list returns everything at once, so filter on the client — no round-trip.
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return data;
    return data.filter(
      (r) =>
        r.name.toLowerCase().includes(q) ||
        (r.description ?? "").toLowerCase().includes(q),
    );
  }, [data, query]);

  const columns = [
    {
      accessorKey: "name",
      header: t("columns.name"),
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <span className="font-medium">{row.original.name}</span>
          {row.original.is_system && (
            <Badge variant="warning" className="font-normal">
              {t("system")}
            </Badge>
          )}
        </div>
      ),
    },
    {
      accessorKey: "description",
      header: t("columns.description"),
      cell: ({ row }) => (
        <span className="line-clamp-1 max-w-sm text-muted-foreground">
          {row.original.description || "—"}
        </span>
      ),
    },
    {
      id: "permissions",
      header: t("columns.permissions"),
      cell: ({ row }) => (
        <Badge variant="secondary">
          {t("permissionCount", { count: grantedCount(row.original) })}
        </Badge>
      ),
    },
    {
      accessorKey: "created_at_human",
      header: t("columns.created"),
      cell: ({ row }) => (
        <span className="text-muted-foreground">{row.original.created_at_human}</span>
      ),
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: ({ row }) => <RoleRowActions role={row.original} />,
    },
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
          <SyncPermissionsButton />
          <Button asChild>
            <Link href="/admin/roles/new">
              <Plus className="size-4" />
              {t("addRole")}
            </Link>
          </Button>
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
                {t("empty.clear")}
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={Shield}
            title={t("empty.title")}
            description={t("empty.desc")}
            action={
              <Button asChild>
                <Link href="/admin/roles/new">
                  <Plus className="size-4" />
                  {t("addRole")}
                </Link>
              </Button>
            }
          />
        )
      ) : (
        <DataTable columns={columns} data={filtered} />
      )}
    </div>
  );
}

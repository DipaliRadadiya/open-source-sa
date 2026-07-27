"use client";

import { Plus, Users, SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { useSetQuery } from "@/hooks/use-set-query";
import { useNavPending } from "@/components/data-table/nav-transition";
import { UserRowActions } from "@/components/admin/users/user-row-actions";
import { useCreateUser } from "@/components/admin/users/users-view";

function initials(name) {
  if (!name) return "?";
  return name
    .split(" ")
    .map((p) => p[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();
}

const MAX_ROLE_BADGES = 2;

export function UsersTable({ data, roles = [], currentUserId, hasFilters }) {
  const t = useTranslations("users");
  const isPending = useNavPending();
  const setQuery = useSetQuery();
  const { openCreate } = useCreateUser();

  if (data.length === 0) {
    return hasFilters ? (
      <EmptyState
        icon={SearchX}
        title={t("empty.filteredTitle")}
        description={t("empty.filteredDesc")}
        action={
          <Button
            variant="outline"
            onClick={() =>
              setQuery(
                { search: undefined, is_admin: undefined },
                { resetPage: true },
              )
            }
          >
            {t("empty.clear")}
          </Button>
        }
      />
    ) : (
      <EmptyState
        icon={Users}
        title={t("empty.title")}
        description={t("empty.desc")}
        action={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            {t("addUser")}
          </Button>
        }
      />
    );
  }

  const columns = [
    {
      accessorKey: "name",
      header: t("columns.name"),
      cell: ({ row }) => (
        <div className="flex items-center gap-2.5">
          <Avatar className="size-7">
            <AvatarFallback className="text-xs">
              {initials(row.original.name)}
            </AvatarFallback>
          </Avatar>
          <span className="font-medium">{row.original.name}</span>
          {row.original.id === currentUserId ? (
            <Badge variant="secondary" className="font-normal">
              {t("you")}
            </Badge>
          ) : null}
        </div>
      ),
    },
    {
      accessorKey: "username",
      header: t("columns.username"),
      cell: ({ row }) => (
        <span className="text-muted-foreground">@{row.original.username}</span>
      ),
    },
    {
      accessorKey: "is_admin",
      header: t("columns.accountType"),
      cell: ({ row }) => {
        const admin = row.original.is_admin;
        return (
          <Badge variant={admin ? "default" : "secondary"}>
            {admin ? t("roleBadge.admin") : t("roleBadge.user")}
          </Badge>
        );
      },
    },
    {
      id: "roles",
      header: t("columns.roles"),
      cell: ({ row }) => {
        const userRoles = row.original.roles ?? [];
        if (!userRoles.length) {
          return <span className="text-muted-foreground">—</span>;
        }
        const shown = userRoles.slice(0, MAX_ROLE_BADGES);
        const extra = userRoles.length - shown.length;
        return (
          <div className="flex flex-wrap items-center gap-1">
            {shown.map((r) => (
              <Badge key={r.id} variant="outline" className="font-normal">
                {r.name}
              </Badge>
            ))}
            {extra > 0 ? (
              <Badge variant="outline" className="font-normal text-muted-foreground">
                +{extra}
              </Badge>
            ) : null}
          </div>
        );
      },
    },
    {
      accessorKey: "created_at_human",
      header: t("columns.joined"),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-muted-foreground">
          {row.original.created_at_human}
        </span>
      ),
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: ({ row }) => (
        <UserRowActions
          user={row.original}
          roles={roles}
          currentUserId={currentUserId}
        />
      ),
    },
  ];

  return (
    <div
      className={cn(
        "transition-opacity",
        isPending && "pointer-events-none opacity-60",
      )}
    >
      <DataTable columns={columns} data={data} />
    </div>
  );
}

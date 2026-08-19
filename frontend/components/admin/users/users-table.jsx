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
import { initials } from "@/lib/format/initials";
import { UsersCards } from "@/components/admin/users/users-cards";

const MAX_ROLE_BADGES = 2;

/* Cells at module level: flexRender treats a cell function's identity as the
 * component type, so inline cells remount — taking the row actions' dialog
 * state with them — every time this table re-renders. Per-table values arrive
 * through `table.options.meta`. */

function NameCell({ row, table }) {
  const t = useTranslations("users");
  return (
    <div className="flex items-center gap-2.5">
      <Avatar className="size-7">
        <AvatarFallback className="text-xs">{initials(row.original.name)}</AvatarFallback>
      </Avatar>
      <span className="font-medium">{row.original.name}</span>
      {row.original.id === table.options.meta.currentUserId ? (
        <Badge variant="secondary" className="font-normal">
          {t("you")}
        </Badge>
      ) : null}
    </div>
  );
}

function UsernameCell({ row }) {
  return <span className="text-muted-foreground">@{row.original.username}</span>;
}

function AccountTypeCell({ row }) {
  const t = useTranslations("users");
  const admin = row.original.is_admin;
  return (
    <Badge variant={admin ? "default" : "secondary"}>
      {admin ? t("roleBadge.admin") : t("roleBadge.user")}
    </Badge>
  );
}

function RolesCell({ row }) {
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
}

function JoinedCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human}
    </span>
  );
}

function RowActionsCell({ row, table }) {
  const { roles, rolesFailed, currentUserId } = table.options.meta;
  return (
    <UserRowActions
      user={row.original}
      roles={roles}
      rolesFailed={rolesFailed}
      currentUserId={currentUserId}
    />
  );
}

export function UsersTable({ data, roles = [], rolesFailed = false, currentUserId, hasFilters }) {
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
    { accessorKey: "name", header: t("columns.name"), cell: NameCell },
    { accessorKey: "username", header: t("columns.username"), cell: UsernameCell },
    { accessorKey: "is_admin", header: t("columns.accountType"), cell: AccountTypeCell },
    { id: "roles", header: t("columns.roles"), cell: RolesCell },
    { accessorKey: "created_at_human", header: t("columns.joined"), cell: JoinedCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("actions.label")}</span>,
      cell: RowActionsCell,
    },
  ];

  return (
    <div
      className={cn(
        "transition-opacity",
        isPending && "pointer-events-none opacity-60",
      )}
    >
      {/* Cards below lg, the table from lg up. */}
      <div className="lg:hidden">
        <UsersCards
          users={data}
          roles={roles}
          currentUserId={currentUserId}
        />
      </div>
      <div className="hidden lg:block">
        <DataTable columns={columns} data={data} meta={{ roles,
          rolesFailed, currentUserId }} />
      </div>
    </div>
  );
}

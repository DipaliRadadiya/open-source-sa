"use client";

import { ScrollText, SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { useSetQuery } from "@/hooks/use-set-query";
import { humanizeActivity, actionBadgeVariant } from "@/lib/activity/labels";

/* Cells at module level: flexRender treats a cell function's identity as the
 * component type, so an inline cell remounts on every render of this table. */

function WhenCell({ row }) {
  return (
    <span className="whitespace-nowrap text-muted-foreground">
      {row.original.created_at_human}
    </span>
  );
}

function UserCell({ row }) {
  const t = useTranslations("activity");
  const u = row.original.user;
  return u ? (
    <span>@{u.username}</span>
  ) : (
    <span className="text-muted-foreground">{t("system")}</span>
  );
}

function EventCell({ row }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {row.original.type ? (
        <Badge variant="outline" className="font-normal">
          {humanizeActivity(row.original.type)}
        </Badge>
      ) : null}
      <Badge variant={actionBadgeVariant(row.original.action)} className="font-normal">
        {humanizeActivity(row.original.action)}
      </Badge>
    </div>
  );
}

function DescriptionCell({ row }) {
  return <span>{row.original.description || "—"}</span>;
}

export function ActivityTable({ data, hasFilters }) {
  const t = useTranslations("activity");
  const setQuery = useSetQuery();

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
                { search: undefined, type: undefined, action: undefined },
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
        icon={ScrollText}
        title={t("empty.title")}
        description={t("empty.desc")}
      />
    );
  }

  const columns = [
    { accessorKey: "created_at_human", header: t("columns.when"), cell: WhenCell },
    { id: "user", header: t("columns.user"), cell: UserCell },
    // Event is shorthand for the description — "Php" + "Install started" against
    // "Started installing PHP 8.5". A phone has room for one of the two, and the
    // sentence is the one worth keeping. Same call as the self-service log.
    { id: "event", header: t("columns.event"), cell: EventCell, meta: { className: "hidden md:table-cell" } },
    {
      accessorKey: "description",
      header: t("columns.description"),
      cell: DescriptionCell,
      meta: { className: "whitespace-normal" },
    },
  ];

  return <DataTable columns={columns} data={data} />;
}

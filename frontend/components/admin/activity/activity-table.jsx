"use client";

import { ScrollText, SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { useSetQuery } from "@/hooks/use-set-query";
import { typeLabel } from "@/lib/activity-log/labels";

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

function TypeCell({ row }) {
  const t = useTranslations("activity");
  return row.original.type ? (
    <Badge variant="outline" className="font-normal">
      {typeLabel(t, row.original.type)}
    </Badge>
  ) : (
    <span className="text-muted-foreground">—</span>
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
    // Hidden on phones: the description column carries the same information.
    { id: "type", header: t("table.type"), cell: TypeCell, meta: { className: "hidden md:table-cell" } },
    {
      accessorKey: "description",
      header: t("columns.description"),
      cell: DescriptionCell,
      meta: { className: "whitespace-normal" },
    },
  ];

  return <DataTable columns={columns} data={data} />;
}

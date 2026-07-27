"use client";

import { ScrollText, SearchX } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { useSetQuery } from "@/hooks/use-set-query";
import { humanizeActivity, actionBadgeVariant } from "@/lib/activity/labels";

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
    {
      accessorKey: "created_at_human",
      header: t("columns.when"),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-muted-foreground">
          {row.original.created_at_human}
        </span>
      ),
    },
    {
      id: "user",
      header: t("columns.user"),
      cell: ({ row }) => {
        const u = row.original.user;
        return u ? (
          <span>@{u.username}</span>
        ) : (
          <span className="text-muted-foreground">{t("system")}</span>
        );
      },
    },
    {
      id: "event",
      header: t("columns.event"),
      cell: ({ row }) => (
        <div className="flex flex-wrap items-center gap-1.5">
          {row.original.type ? (
            <Badge variant="outline" className="font-normal">
              {humanizeActivity(row.original.type)}
            </Badge>
          ) : null}
          <Badge
            variant={actionBadgeVariant(row.original.action)}
            className="font-normal"
          >
            {humanizeActivity(row.original.action)}
          </Badge>
        </div>
      ),
    },
    {
      accessorKey: "description",
      header: t("columns.description"),
      cell: ({ row }) => <span>{row.original.description || "—"}</span>,
    },
  ];

  return <DataTable columns={columns} data={data} />;
}

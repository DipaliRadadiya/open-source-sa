"use client";

import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { DataTable } from "@/components/ui/data-table";
import { humanizeActivity, actionBadgeVariant } from "@/lib/activity/labels";

export function AccountActivity({ data }) {
  const t = useTranslations("account");

  const columns = [
    {
      accessorKey: "created_at_human",
      header: t("activity.when"),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-muted-foreground">
          {row.original.created_at_human}
        </span>
      ),
    },
    {
      id: "event",
      header: t("activity.action"),
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
      header: t("activity.description"),
      cell: ({ row }) => <span>{row.original.description || "—"}</span>,
    },
  ];

  return <DataTable columns={columns} data={data} emptyMessage={t("activity.empty")} />;
}

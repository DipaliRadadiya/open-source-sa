"use client";

import { useTranslations } from "next-intl";
import { Archive } from "lucide-react";
import { BACKUP_PERIODS, BACKUP_TYPES } from "@/lib/schemas/backup";
import { BackupsCards } from "@/components/backups/backups-cards";
import { BackupsHistoryTable } from "@/components/backups/backups-history-table";
import { FacetSelect } from "@/components/data-table/facet-select";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ListCard } from "@/components/data-table/list-card";
import { EmptyState } from "@/components/data-table/empty-state";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";

// No Restore here: a deleted site's backup cannot be restored, and a database
// copy is restored from the Backups tab of the database it should go into.
export function ArchivedBackups({ backups, canManage, hasFilters, pager }) {
  const t = useTranslations("backups.archivedTab");
  const th = useTranslations("backups.history");

  const listProps = {
    backups,
    // Applications and databases share the list.
    siteHeader: t("columnName"),
    canRestore: canManage,
    canDelete: canManage,
    canDownload: canManage,
    canRun: false,
    showRestore: false,
    // The delete dialog re-reads the list itself before it closes.
    onDeleted: () => {},
  };

  const toolbar = (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
      <FacetSelect
        paramKey="type"
        label={th("columns.type")}
        allLabel={th("allTypes")}
        options={BACKUP_TYPES.map((value) => ({ value, label: th(`types.${value}`) }))}
        className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
      />
      <div className="flex items-center gap-3 sm:contents">
        <FacetSelect
          paramKey="period"
          label={th("columns.when")}
          allLabel={th("anyTime")}
          options={BACKUP_PERIODS.map((value) => ({ value, label: th("lastDays", { count: Number(value) }) }))}
          className="min-w-0 flex-1 sm:w-auto sm:min-w-36 sm:flex-none sm:shrink-0"
        />
        <div className="sm:ml-auto">
          <RefreshButton />
        </div>
      </div>
    </div>
  );

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{t("intro")}</p>

      {backups.length === 0 ? (
        <ListCard toolbar={toolbar}>
          <EmptyState
            icon={Archive}
            title={hasFilters ? th("emptyFiltered.title") : t("empty.title")}
            description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
            badge={hasFilters ? "search" : null}
            action={hasFilters ? <ClearFiltersButton keys={["type", "period"]} /> : null}
          />
        </ListCard>
      ) : (
        // Same container name as History, so the table folds its Size column the same way.
        <div className="@container/history">
          <ListCard from="c900" toolbar={toolbar} footer={pager}>
            <div className="@min-[900px]:hidden">
              <BackupsCards {...listProps} />
            </div>
            <div className="hidden @min-[900px]:block">
              <BackupsHistoryTable {...listProps} bare />
            </div>
          </ListCard>
        </div>
      )}
    </div>
  );
}

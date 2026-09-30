"use client";

import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { SearchInput } from "@/components/data-table/search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { useSetQuery } from "@/hooks/use-set-query";
import { typeLabel } from "@/lib/activity-log/labels";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Search + type/action filters for both activity views. The admin log and a
 * user's own log return the same filter shape, so one toolbar serves both —
 * only the data source differs.
 *
 * `extraQuery` is merged into every navigation: the account page keeps its tab
 * in the URL, and filtering must not drop it.
 */
// No action filter: the server can only filter by an exact event id, and the
// 166 ids were English in every language. Type and search stay.
export function ActivityToolbar({ types, searchKey = "searchPlaceholder", extraQuery }) {
  const t = useTranslations("activity");
  const setQuery = useSetQuery();
  const searchParams = useSearchParams();

  const selectedType = searchParams.get("type") ?? "all";

  // Own-history filters are built from rows that actually exist, so an empty
  // list means this user has no activity — offering "All types" over nothing
  // is a control that can only disappoint.
  const hasFilters = types.length > 0;

  const apply = (updates) => setQuery({ ...updates, ...extraQuery }, { resetPage: true });

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
      {/* The admin log searches actor names too; a personal history has one
          actor, so promising "or user" there would be a lie. */}
      <SearchInput placeholder={t(searchKey)} extraQuery={extraQuery} />

      {hasFilters ? (
        <>
          <Select
            value={selectedType}
            onValueChange={(v) =>
              // Clear the action when the type changes — it may not apply anymore.
              apply({ type: v === "all" ? undefined : v, action: undefined })
            }
          >
            <SelectTrigger className="w-full sm:w-40" aria-label={t("table.type")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              <SelectItem value="all">{t("filter.allTypes")}</SelectItem>
              {types.map((v) => (
                <SelectItem key={v} value={v}>
                  {typeLabel(t, v)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </>
      ) : null}

      <div className="sm:ml-auto">
        <RefreshButton />
      </div>
    </div>
  );
}

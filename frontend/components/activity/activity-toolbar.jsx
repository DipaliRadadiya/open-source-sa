"use client";

import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { SearchInput } from "@/components/data-table/search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { useSetQuery } from "@/hooks/use-set-query";
import { humanizeActivity } from "@/lib/activity/labels";
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
export function ActivityToolbar({ types, actions, searchKey = "searchPlaceholder", extraQuery }) {
  const t = useTranslations("activity");
  const setQuery = useSetQuery();
  const searchParams = useSearchParams();

  const selectedType = searchParams.get("type") ?? "all";
  const selectedAction = searchParams.get("action") ?? "all";

  // Action options depend on the selected type: `all` verbs by default, or the
  // type's own verbs once a type is picked.
  const actionList =
    (selectedType !== "all" && actions[selectedType]) || actions.all || [];

  // Own-history filters are built from rows that actually exist, so an empty
  // list means this user has no activity — offering "All types" over nothing
  // is a control that can only disappoint.
  const hasFilters = types.length > 0 || (actions.all?.length ?? 0) > 0;

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
            <SelectTrigger className="w-full sm:w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              <SelectItem value="all">{t("filter.allTypes")}</SelectItem>
              {types.map((v) => (
                <SelectItem key={v} value={v}>
                  {humanizeActivity(v)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select
            value={selectedAction}
            onValueChange={(v) => apply({ action: v === "all" ? undefined : v })}
          >
            <SelectTrigger className="w-full sm:w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              <SelectItem value="all">{t("filter.allActions")}</SelectItem>
              {actionList.map((v) => (
                <SelectItem key={v} value={v}>
                  {humanizeActivity(v)}
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

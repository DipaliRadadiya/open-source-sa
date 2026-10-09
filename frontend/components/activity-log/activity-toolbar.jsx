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
import { cn } from "@/lib/utils";
import { SELECT_WELL } from "@/components/data-table/toolbar-well";

// `extraQuery` is merged into every navigation so the account page keeps its tab.
// No action filter: the API only filters by exact, untranslated event ids.
// `security` (admin log only) adds "Security events" — sign-ins, failed sign-ins, user and role changes.
export function ActivityToolbar({ types, kinds = [], security = false, searchKey = "searchPlaceholder", extraQuery }) {
  const t = useTranslations("activity");
  const setQuery = useSetQuery();
  const searchParams = useSearchParams();

  const selectedType = searchParams.get("type") ?? "all";
  const selectedKind = searchParams.get("security") === "1" ? "security" : (searchParams.get("kind") ?? "all");

  // Own-history types come from existing rows, so empty means no activity at all.
  const hasFilters = types.length > 0;

  const apply = (updates) => setQuery({ ...updates, ...extraQuery }, { resetPage: true });

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
      {/* The admin log also searches actor names; a personal history has one actor. */}
      <SearchInput placeholder={t(searchKey)} extraQuery={extraQuery} />

      {/* Phone: the filter and Refresh share a row, so Refresh is not left alone on one. */}
      <div className="flex items-center gap-3 sm:contents">
        {hasFilters ? (
          <Select
            value={selectedType}
            onValueChange={(v) =>
              // The current action may not apply to the new type.
              apply({ type: v === "all" ? undefined : v, action: undefined })
            }
          >
            <SelectTrigger className={cn(SELECT_WELL, "min-w-0 flex-1 sm:w-40 sm:flex-none")} aria-label={t("table.type")}>
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
        ) : null}

        {kinds.length || security ? (
          <Select
            value={selectedKind}
            onValueChange={(v) =>
              apply({
                kind: v === "all" || v === "security" ? undefined : v,
                security: v === "security" ? "1" : undefined,
              })
            }
          >
            <SelectTrigger className={cn(SELECT_WELL, "min-w-0 flex-1 sm:w-40 sm:flex-none")} aria-label={t("filter.kind")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              <SelectItem value="all">{t("filter.allKinds")}</SelectItem>
              {kinds.map((kind) => (
                <SelectItem key={kind.value} value={kind.value}>
                  {kind.label}
                </SelectItem>
              ))}
              {security ? <SelectItem value="security">{t("filter.security")}</SelectItem> : null}
            </SelectContent>
          </Select>
        ) : null}

        <div className="ml-auto">
          <RefreshButton />
        </div>
      </div>
    </div>
  );
}

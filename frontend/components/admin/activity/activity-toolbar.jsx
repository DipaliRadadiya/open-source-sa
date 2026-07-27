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

export function ActivityToolbar({ types, actions }) {
  const t = useTranslations("activity");
  const setQuery = useSetQuery();
  const searchParams = useSearchParams();

  const selectedType = searchParams.get("type") ?? "all";
  const selectedAction = searchParams.get("action") ?? "all";

  // Action options depend on the selected type: `all` verbs by default, or the
  // type's own verbs once a type is picked.
  const actionList =
    (selectedType !== "all" && actions[selectedType]) || actions.all || [];

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
      <SearchInput placeholder={t("searchPlaceholder")} />

      <Select
        value={selectedType}
        onValueChange={(v) =>
          // Clear the action when the type changes — it may not apply anymore.
          setQuery(
            { type: v === "all" ? undefined : v, action: undefined },
            { resetPage: true },
          )
        }
      >
        <SelectTrigger className="h-9 w-full sm:w-40">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
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
        onValueChange={(v) =>
          setQuery({ action: v === "all" ? undefined : v }, { resetPage: true })
        }
      >
        <SelectTrigger className="h-9 w-full sm:w-56">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{t("filter.allActions")}</SelectItem>
          {actionList.map((v) => (
            <SelectItem key={v} value={v}>
              {humanizeActivity(v)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <div className="sm:ml-auto">
        <RefreshButton />
      </div>
    </div>
  );
}

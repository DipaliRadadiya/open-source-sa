"use client";

import { useSearchParams } from "next/navigation";
import { useSetQuery } from "@/hooks/use-set-query";
import { FilterSelect } from "@/components/data-table/filter-select";

/**
 * URL-driven filter select. Writes `paramKey` (or clears it on the "all"
 * option) and resets to page 1. `options` is [{ value, label }].
 *
 * The control itself is `FilterSelect`; this only supplies where the value
 * lives. Screens that filter a list already in memory use `FilterSelect`
 * directly — same dropdown, no navigation per keystroke.
 */
export function FacetSelect({ paramKey, allLabel, options, className }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();

  return (
    <FilterSelect
      value={searchParams.get(paramKey) ?? "all"}
      onChange={(value) =>
        setQuery({ [paramKey]: value === "all" ? undefined : value }, { resetPage: true })
      }
      allLabel={allLabel}
      options={options}
      className={className}
    />
  );
}

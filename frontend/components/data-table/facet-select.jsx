import { useSearchParams } from "next/navigation";
import { useSetQuery } from "@/hooks/use-set-query";
import { FilterSelect } from "@/components/data-table/filter-select";

/**
 * URL-driven filter select. Writes `paramKey` (or clears it on the "all"
 * option) and resets to page 1. `options` is [{ value, label }].
 *
 * The control itself is `FilterSelect`; in-memory screens use it directly.
 */
export function FacetSelect({ paramKey, allLabel, options, className, label }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();

  // Only a value this control offers: Radix renders a blank trigger for an
  // unknown value (e.g. a hand-edited `?active=bogus`).
  const raw = searchParams.get(paramKey);
  const value = options.some((option) => option.value === raw) ? raw : "all";

  return (
    <FilterSelect
      value={value}
      onChange={(value) =>
        setQuery({ [paramKey]: value === "all" ? undefined : value }, { resetPage: true })
      }
      allLabel={allLabel}
      options={options}
      className={className}
      label={label}
    />
  );
}

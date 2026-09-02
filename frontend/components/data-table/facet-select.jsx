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

  // Only a value this control actually offers. The URL is editable and shared,
  // so `?active=bogus` is reachable — and the raw value used to go straight to
  // Radix, which matched no item and rendered the trigger BLANK. The filter
  // was still applied server-side, so the list showed a subset with nothing on
  // screen saying why and no option selected to clear.
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
    />
  );
}

import { useSearchParams } from "next/navigation";
import { useSetQuery } from "@/hooks/use-set-query";
import { FilterSelect } from "@/components/data-table/filter-select";

export function FacetSelect({ paramKey, allLabel, options, className, label }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();

  // Radix shows a blank trigger for an unknown value like ?active=bogus.
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

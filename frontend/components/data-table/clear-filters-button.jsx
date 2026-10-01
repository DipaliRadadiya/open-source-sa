import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { useSetQuery } from "@/hooks/use-set-query";

// `keys` lists every filter param; `SearchInput` follows the URL so the search box empties too.
export function ClearFiltersButton({
  keys = [],
  extraQuery,
  label,
  // Lighter variants for use inside a chip or sentence.
  variant = "outline",
  size,
  className,
}) {
  const t = useTranslations("common");
  const setQuery = useSetQuery();

  return (
    <Button
      variant={variant}
      size={size}
      className={className}
      onClick={() =>
        setQuery(
          { ...Object.fromEntries(keys.map((key) => [key, undefined])), ...extraQuery },
          { resetPage: true },
        )
      }
    >
      {label ?? t("clearFilters")}
    </Button>
  );
}

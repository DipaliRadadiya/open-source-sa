import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { useSetQuery } from "@/hooks/use-set-query";

export function ClearFiltersButton({
  keys = [],
  extraQuery,
  label,
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

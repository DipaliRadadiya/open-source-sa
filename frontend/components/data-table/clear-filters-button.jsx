import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { useSetQuery } from "@/hooks/use-set-query";

/**
 * The way out of a filtered-empty table.
 *
 * Nine screens hand-assembled this button and four forgot it, so Activity log
 * and Restores told people "nothing matches those filters" and left them to
 * work out which of four controls to undo — while the identical dead end on
 * Applications offered one click. Duplication is exactly why the four were
 * missed, so the button is one component now.
 *
 * `keys` names every param the screen filters by. Each is cleared to
 * `undefined`, which drops it from the URL — and `SearchInput` follows the URL,
 * so the search box empties with it rather than keeping a term that matches
 * nothing.
 */
export function ClearFiltersButton({ keys = [], extraQuery, label }) {
  const t = useTranslations("common");
  const setQuery = useSetQuery();

  return (
    <Button
      variant="outline"
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

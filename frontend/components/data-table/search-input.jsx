import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Search, X, Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { useSetQuery } from "@/hooks/use-set-query";
import { nextSearchValue } from "@/lib/tables/search-sync";
import { useNavPending } from "@/components/data-table/nav-transition";

/**
 * Debounced search box that writes the `search` param to the URL (resetting to
 * page 1). Shows a spinner while a navigation is pending and a clear (×) button
 * when it has a value. Reusable across any list page.
 */
export function SearchInput({
  placeholder,
  paramKey = "search",
  delay = 300,
  // Merged into every navigation, for params the router doesn't own (the
  // account page keeps its active tab in the URL via history.replaceState).
  extraQuery,
}) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const pending = useNavPending();
  const tc = useTranslations("common");
  const urlValue = searchParams.get(paramKey) ?? "";
  const [value, setValue] = useState(urlValue);
  const first = useRef(true);

  /*
   * Follow the URL when something else changes it.
   *
   * This box seeded itself from the URL once and then owned its own value, so
   * anything that cleared `search` elsewhere — "Clear filters" on the
   * no-matches state, the back button, a link with its own query — emptied the
   * table and left the term sitting in the input. The list then said "no
   * matches" for a search that was no longer being applied.
   *
   * Synced during render rather than in an effect: an effect would paint the
   * stale term for a frame first, and setting state in one is the cascading
   * render the lint rule refuses. Typing is unaffected — the URL only catches
   * up after the debounce below, and by then the two already agree.
   */
  const [seenUrlValue, setSeenUrlValue] = useState(urlValue);
  if (seenUrlValue !== urlValue) {
    setSeenUrlValue(urlValue);
    setValue(nextSearchValue({ urlValue, seenUrlValue, value }));
  }

  useEffect(() => {
    // Don't fire on mount — only on user edits.
    if (first.current) {
      first.current = false;
      return;
    }
    const id = setTimeout(() => {
      setQuery(
        { [paramKey]: value.trim() || undefined, ...extraQuery },
        { resetPage: true },
      );
    }, delay);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  return (
    <div className="relative w-full sm:max-w-xs">
      {pending ? (
        <Loader2 className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
      ) : (
        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      )}
      <Input
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder={placeholder}
        className="px-8"
      />
      {value ? (
        <button
          type="button"
          onClick={() => setValue("")}
          aria-label={tc("clearSearch")}
          className="absolute right-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
        >
          <X className="size-4" />
        </button>
      ) : null}
    </div>
  );
}

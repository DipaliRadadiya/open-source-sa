import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Search, X, Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { useSetQuery } from "@/hooks/use-set-query";
import { nextSearchValue } from "@/lib/tables/search-sync";
import { useNavPending } from "@/components/data-table/nav-transition";
import { SEARCH_WELL } from "@/components/data-table/toolbar-well";
import { cn } from "@/lib/utils";

export function SearchInput({
  placeholder,
  paramKey = "search",
  delay = 300,
  extraQuery,
}) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const pending = useNavPending();
  const tc = useTranslations("common");
  const urlValue = searchParams.get(paramKey) ?? "";
  const [value, setValue] = useState(urlValue);
  const first = useRef(true);
  const input = useRef(null);

  // Synced during render, not in an effect, so a stale term is never painted.
  const [seenUrlValue, setSeenUrlValue] = useState(urlValue);
  if (seenUrlValue !== urlValue) {
    setSeenUrlValue(urlValue);
    setValue(nextSearchValue({ urlValue, seenUrlValue, value }));
  }

  useEffect(() => {
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
    // `extraQuery` MUST stay excluded: it is a new object every render and
    // would keep resetting the timer.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  return (
    <div className="relative w-full sm:max-w-xs">
      {pending ? (
        <Loader2 className="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
      ) : (
        <Search className="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 text-muted-foreground" />
      )}
      <Input
        ref={input}
        data-search-input
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder={placeholder}
        className={cn(SEARCH_WELL, "pl-9", value ? "pr-8" : "pr-3")}
      />
      {value ? (
        <button
          type="button"
          onClick={() => {
            setValue("");
            input.current?.focus();
          }}
          aria-label={tc("clearSearch")}
          className="absolute right-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
        >
          <X className="size-4" />
        </button>
      ) : null}
    </div>
  );
}

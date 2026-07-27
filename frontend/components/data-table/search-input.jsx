"use client";

import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Search, X, Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { useSetQuery } from "@/hooks/use-set-query";
import { useNavPending } from "@/components/data-table/nav-transition";

/**
 * Debounced search box that writes the `search` param to the URL (resetting to
 * page 1). Shows a spinner while a navigation is pending and a clear (×) button
 * when it has a value. Reusable across any list page.
 */
export function SearchInput({ placeholder, paramKey = "search", delay = 300 }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const pending = useNavPending();
  const tc = useTranslations("common");
  const [value, setValue] = useState(searchParams.get(paramKey) ?? "");
  const first = useRef(true);

  useEffect(() => {
    // Don't fire on mount — only on user edits.
    if (first.current) {
      first.current = false;
      return;
    }
    const id = setTimeout(() => {
      setQuery({ [paramKey]: value.trim() || undefined }, { resetPage: true });
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

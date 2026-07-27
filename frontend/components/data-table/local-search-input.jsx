"use client";

import { Search, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";

/**
 * Controlled search box for in-memory (client-side) list filtering — the
 * counterpart to {@link SearchInput}, which drives the URL for server-paginated
 * lists. Owns no state: the parent holds `value` and filters its own data.
 * Renders the search icon and a clear (×) button when non-empty.
 */
export function LocalSearchInput({ value, onChange, placeholder }) {
  const tc = useTranslations("common");
  return (
    <div className="relative w-full sm:max-w-xs">
      <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="px-8"
      />
      {value ? (
        <button
          type="button"
          onClick={() => onChange("")}
          aria-label={tc("clearSearch")}
          className="absolute right-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
        >
          <X className="size-4" />
        </button>
      ) : null}
    </div>
  );
}

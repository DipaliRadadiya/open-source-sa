import { Search, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { SEARCH_WELL } from "@/components/data-table/toolbar-well";

// In-memory counterpart to {@link SearchInput} (URL-driven); the parent holds `value` and filters.
export function LocalSearchInput({ value, onChange, placeholder, className }) {
  const tc = useTranslations("common");
  return (
    <div className={cn("relative w-full sm:max-w-xs", className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        // Room for the clear button only once there is something to clear, so an empty
        // box gives its placeholder the full width.
        className={cn(SEARCH_WELL, "pl-9", value ? "pr-8" : "pr-3")}
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

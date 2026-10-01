import { Search, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";

/**
 * Controlled search box for in-memory list filtering; the counterpart to
 * {@link SearchInput} (URL-driven). The parent holds `value` and filters.
 */
export function LocalSearchInput({ value, onChange, placeholder, className }) {
  const tc = useTranslations("common");
  return (
    <div className={cn("relative w-full sm:max-w-xs", className)}>
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

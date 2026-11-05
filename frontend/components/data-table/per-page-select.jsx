import { useId } from "react";
import { useSearchParams } from "next/navigation";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { useSetQuery } from "@/hooks/use-set-query";
import { savePerPage } from "@/lib/tables/per-page-cookie";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

// Writes `per_page` and resets to page 1. The choice is also saved, so every list
// starts at it (Krishna, 5 Nov). `fallback`: the size the list was served with.
export function PerPageSelect({ label, value, onValueChange, fallback }) {
  // Links the visible "Per page" label so the combobox has a name.
  const labelId = useId();
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  // Without a valid `per_page` the fetcher used the remembered size; match it here.
  const fromUrl = searchParams.get("per_page");
  const served = PER_PAGE_OPTIONS.includes(Number(fallback)) ? String(fallback) : String(PER_PAGE_OPTIONS[0]);
  const current = value ?? (PER_PAGE_OPTIONS.includes(Number(fromUrl)) ? fromUrl : served);
  const apply = onValueChange ?? ((next) => setQuery({ per_page: next }, { resetPage: true }));
  const change = (next) => {
    savePerPage(next);
    apply(next);
  };

  return (
    <div className="flex items-center gap-2 text-sm text-muted-foreground">
      <span id={labelId} className="whitespace-nowrap">{label}</span>
      <Select value={current} onValueChange={change}>
        <SelectTrigger className="w-[4.5rem]" aria-labelledby={labelId}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {PER_PAGE_OPTIONS.map((n) => (
            <SelectItem key={n} value={String(n)}>
              {n}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

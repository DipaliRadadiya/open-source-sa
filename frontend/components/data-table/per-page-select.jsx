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

export function PerPageSelect({ label, value, onValueChange, fallback }) {
  const labelId = useId();
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
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

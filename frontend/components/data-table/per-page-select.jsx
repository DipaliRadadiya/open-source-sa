import { useSearchParams } from "next/navigation";
import { PER_PAGE_OPTIONS } from "@/lib/schemas/user";
import { useSetQuery } from "@/hooks/use-set-query";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Rows-per-page selector, URL-driven (writes `per_page`, resets to page 1).
 */
export function PerPageSelect({ label, value, onValueChange }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const current = value ?? searchParams.get("per_page") ?? "10";
  const change = onValueChange ?? ((next) => setQuery({ per_page: next }, { resetPage: true }));

  return (
    <div className="flex items-center gap-2 text-sm text-muted-foreground">
      <span className="whitespace-nowrap">{label}</span>
      <Select value={current} onValueChange={change}>
        <SelectTrigger className="w-[4.5rem]">
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

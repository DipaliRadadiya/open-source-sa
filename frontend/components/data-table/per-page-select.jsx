"use client";

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
export function PerPageSelect({ label }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const current = searchParams.get("per_page") ?? "10";

  return (
    <div className="flex items-center gap-2 text-sm text-muted-foreground">
      <span className="whitespace-nowrap">{label}</span>
      <Select
        value={current}
        onValueChange={(v) => setQuery({ per_page: v }, { resetPage: true })}
      >
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

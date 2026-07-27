"use client";

import { useSearchParams } from "next/navigation";
import { useSetQuery } from "@/hooks/use-set-query";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * URL-driven filter select. Writes `paramKey` (or clears it on the "all"
 * option) and resets to page 1. `options` is [{ value, label }].
 */
export function FacetSelect({ paramKey, allLabel, options, className }) {
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();
  const value = searchParams.get(paramKey) ?? "all";

  return (
    <Select
      value={value}
      onValueChange={(v) =>
        setQuery({ [paramKey]: v === "all" ? undefined : v }, { resetPage: true })
      }
    >
      <SelectTrigger className={className}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{allLabel}</SelectItem>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

"use client";

import { useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Check, ChevronsUpDown, Search } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";

/**
 * Searchable single-select. The house rule is a Combobox — not a bare Select —
 * for any data-driven or long list (repos, branches, system users): scanning a
 * scroll-only dropdown for one entry is the slow path, typing to filter is the
 * fast one.
 *
 * Deliberately built on Popover + a filtered list rather than pulling in cmdk —
 * one dependency-free primitive the whole panel can share. `options` are
 * `{ value, label, hint? }`; `value`/`onChange` speak the option value.
 */
export function Combobox({
  options = [],
  value,
  onChange,
  placeholder,
  searchPlaceholder,
  empty,
  disabled = false,
  className,
  id,
}) {
  const t = useTranslations("common");
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const searchRef = useRef(null);

  const selected = options.find((option) => String(option.value) === String(value));
  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return options;
    return options.filter((option) =>
      [option.label, option.hint, String(option.value)]
        .filter(Boolean)
        .some((text) => text.toLowerCase().includes(term)),
    );
  }, [options, query]);

  function handleOpenChange(next) {
    setOpen(next);
    if (!next) setQuery("");
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          id={id}
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            "w-full justify-between font-normal",
            !selected && "text-muted-foreground",
            className,
          )}
        >
          <span className="truncate">{selected ? selected.label : (placeholder ?? t("select"))}</span>
          <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[--radix-popover-trigger-width] p-0"
        align="start"
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          searchRef.current?.focus();
        }}
      >
        <div className="flex items-center gap-2 border-b px-3">
          <Search className="size-4 shrink-0 text-muted-foreground" />
          <Input
            ref={searchRef}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={searchPlaceholder ?? t("search")}
            className="h-9 border-0 px-0 shadow-none focus-visible:ring-0"
          />
        </div>
        <div className="max-h-64 overflow-y-auto p-1">
          {filtered.length ? (
            filtered.map((option) => {
              const isSelected = String(option.value) === String(value);
              return (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => {
                    onChange?.(String(option.value));
                    handleOpenChange(false);
                  }}
                  className={cn(
                    "flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground",
                    isSelected && "bg-accent/60",
                  )}
                >
                  <Check className={cn("mt-0.5 size-4 shrink-0", isSelected ? "opacity-100 text-primary" : "opacity-0")} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{option.label}</span>
                    {option.hint ? (
                      <span className="block truncate text-xs text-muted-foreground">{option.hint}</span>
                    ) : null}
                  </span>
                </button>
              );
            })
          ) : (
            <p className="px-2 py-6 text-center text-sm text-muted-foreground">{empty ?? t("noResults")}</p>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}

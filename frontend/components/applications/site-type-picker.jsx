"use client";

import { useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  Check,
  ChevronsUpDown,
  Code2,
  LayoutTemplate,
  PackageOpen,
  Search,
  Sparkles,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { useChromeOffset } from "@/hooks/use-chrome-offset";

function TypeIcon({ type, className }) {
  const Icon =
    type.method === "git"
      ? Code2
      : type.has_installer
        ? PackageOpen
        : LayoutTemplate;
  return <Icon className={className} aria-hidden />;
}

/**
 * The app type is the decision the whole rest of the form hangs off. A grid of
 * ~17 cards is scannable but eats a screen of height before the form even
 * starts, so it's a searchable dropdown instead: the trigger doubles as the
 * one-row summary of the choice, and the list keeps what made the cards useful —
 * icon, name, a one-line tagline so you learn what a thing is before picking,
 * popular-first ordering, and a type this server can't run shown disabled with
 * the reason rather than hidden.
 */
export function SiteTypePicker({ types = [], value, onChange }) {
  const t = useTranslations("applications");
  const [open, setOpen] = useState(false);
  // The sticky header is not empty space, whatever the positioning engine
  // thinks — see hooks/use-chrome-offset.js.
  const [chromeOffset, measureChrome] = useChromeOffset();
  const [query, setQuery] = useState("");
  const searchRef = useRef(null);

  const ordered = useMemo(
    () =>
      [...types].sort(
        (a, b) =>
          Number(b.popular) - Number(a.popular) || a.title.localeCompare(b.title),
      ),
    [types],
  );
  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return ordered;
    return ordered.filter((type) =>
      [type.title, type.tagline, type.category]
        .filter(Boolean)
        .some((text) => text.toLowerCase().includes(term)),
    );
  }, [ordered, query]);

  const selectedType = types.find((type) => type.name === value);

  function handleOpenChange(next) {
    if (next) measureChrome();
    setOpen(next);
    if (!next) setQuery("");
  }

  function pick(name) {
    onChange(name);
    handleOpenChange(false);
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-label={t("chooseType")}
          aria-expanded={open}
          className={cn(
            "h-auto w-full justify-between gap-2 py-2 font-normal",
            !selectedType && "text-muted-foreground",
          )}
        >
          {selectedType ? (
            <span className="flex min-w-0 items-center gap-2.5">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                <TypeIcon type={selectedType} className="size-4" />
              </span>
              <span className="min-w-0 text-left">
                <span className="block truncate font-medium text-foreground">
                  {selectedType.title}
                </span>
                {selectedType.tagline ? (
                  <span className="block truncate text-xs text-muted-foreground">
                    {selectedType.tagline}
                  </span>
                ) : null}
              </span>
            </span>
          ) : (
            <span className="truncate">{t("form.typePlaceholder")}</span>
          )}
          <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="flex max-h-(--radix-popover-content-available-height) w-(--radix-popover-trigger-width) flex-col p-0"
        align="start"
        collisionPadding={{ top: chromeOffset, bottom: 12, left: 12, right: 12 }}
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          searchRef.current?.focus();
        }}
      >
        <div className="flex shrink-0 items-center gap-2 border-b px-3">
          <Search className="size-4 shrink-0 text-muted-foreground" />
          <Input
            ref={searchRef}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t("form.typeSearch")}
            className="h-9 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0 dark:bg-transparent"
            aria-label={t("form.typeSearch")}
          />
        </div>
        <div className="max-h-80 min-h-0 flex-1 overflow-y-auto p-1">
          {filtered.length ? (
            filtered.map((type) => {
              const isSelected = type.name === value;
              const disabled = !type.available;
              return (
                <button
                  key={type.name}
                  type="button"
                  disabled={disabled}
                  aria-pressed={isSelected}
                  onClick={() => pick(type.name)}
                  className={cn(
                    "flex w-full items-start gap-2.5 rounded-md px-2 py-2 text-left transition-colors",
                    "hover:bg-accent hover:text-accent-foreground",
                    isSelected && "bg-accent/60",
                    disabled &&
                      "cursor-not-allowed opacity-60 hover:bg-transparent hover:text-inherit",
                  )}
                >
                  <span
                    className={cn(
                      "flex size-7 shrink-0 items-center justify-center rounded-md",
                      isSelected
                        ? "bg-primary text-primary-foreground"
                        : "bg-primary/10 text-primary",
                    )}
                  >
                    <TypeIcon type={type} className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2">
                      <span className="truncate text-sm font-medium">
                        {type.title}
                      </span>
                      {type.popular ? (
                        <Badge
                          variant="secondary"
                          className="shrink-0 gap-1 font-normal"
                        >
                          <Sparkles className="size-3" />
                          {t("form.popular")}
                        </Badge>
                      ) : null}
                    </span>
                    {type.tagline ? (
                      <span className="mt-0.5 block truncate text-xs leading-5 text-muted-foreground">
                        {type.tagline}
                      </span>
                    ) : null}
                    {disabled && type.unavailable_reason ? (
                      <span className="mt-0.5 flex items-start gap-1 text-xs leading-5 text-destructive">
                        <TriangleAlert className="mt-0.5 size-3 shrink-0" />
                        {type.unavailable_reason}
                      </span>
                    ) : null}
                  </span>
                  <Check
                    className={cn(
                      "mt-0.5 size-4 shrink-0 text-primary",
                      isSelected ? "opacity-100" : "opacity-0",
                    )}
                  />
                </button>
              );
            })
          ) : (
            <p className="px-2 py-6 text-center text-sm text-muted-foreground">
              {t("form.typeNoResults", { query })}
            </p>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}

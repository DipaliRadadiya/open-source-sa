"use client";

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { Code2, LayoutTemplate, PackageOpen, Pencil, Search, Sparkles, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

function TypeIcon({ type, className }) {
  const Icon = type.method === "git" ? Code2 : type.has_installer ? PackageOpen : LayoutTemplate;
  return <Icon className={className} aria-hidden />;
}

/**
 * The app type is the decision the whole rest of the form hangs off, so it gets
 * scannable cards — icon, name and one line of what it is — rather than a
 * 17-row dropdown where you only learn what a thing is after picking it.
 *
 * The grid is tall, and once a type is chosen the user is done with it, so it
 * collapses to a one-row summary of the choice with a Change action — the whole
 * form below then rises into view instead of sitting a screen down. Popular
 * types lead; a type this server can't run is shown disabled with the reason,
 * never hidden.
 */
export function SiteTypePicker({ types = [], value, onChange }) {
  const t = useTranslations("applications");
  const [query, setQuery] = useState("");
  const [expanded, setExpanded] = useState(false);

  const ordered = useMemo(
    () => [...types].sort((a, b) => Number(b.popular) - Number(a.popular) || a.title.localeCompare(b.title)),
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
  // Grid while choosing (or when re-opened via Change); the compact summary once
  // a choice stands.
  const showGrid = !selectedType || expanded;

  function pick(name) {
    onChange(name);
    setExpanded(false);
    setQuery("");
  }

  if (!showGrid) {
    return (
      <div className="flex min-w-0 items-center gap-3 rounded-xl border border-primary/40 bg-primary/[0.04] p-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <TypeIcon type={selectedType} className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          {/* line-clamp (not truncate): a nowrap label contributes its full text
              width to the grid track's max-content and overflows on a phone;
              wrapping collapses that to the longest word. */}
          <p className="line-clamp-1 font-medium leading-tight">{selectedType.title}</p>
          {selectedType.tagline ? (
            <p className="line-clamp-2 text-xs text-muted-foreground">{selectedType.tagline}</p>
          ) : null}
        </div>
        <Button type="button" variant="outline" size="sm" className="shrink-0" onClick={() => setExpanded(true)}>
          <Pencil className="size-3.5" />
          {t("form.changeType")}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {types.length > 8 ? (
        <div className="relative">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t("form.typeSearch")}
            className="pl-9"
            aria-label={t("form.typeSearch")}
          />
        </div>
      ) : null}

      {filtered.length ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {filtered.map((type) => {
            const selected = type.name === value;
            const disabled = !type.available;
            return (
              <button
                key={type.name}
                type="button"
                disabled={disabled}
                aria-pressed={selected}
                onClick={() => pick(type.name)}
                className={cn(
                  "flex flex-col gap-2 rounded-xl border bg-card p-3.5 text-left transition-colors",
                  "hover:border-primary/40 hover:bg-primary/[0.03]",
                  selected && "border-primary bg-primary/[0.05] ring-1 ring-primary",
                  disabled && "cursor-not-allowed opacity-60 hover:border-border hover:bg-card",
                )}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-lg", selected ? "bg-primary text-primary-foreground" : "bg-primary/10 text-primary")}>
                    <TypeIcon type={type} className="size-4" />
                  </span>
                  {type.popular ? (
                    <Badge variant="secondary" className="gap-1 font-normal">
                      <Sparkles className="size-3" />
                      {t("form.popular")}
                    </Badge>
                  ) : null}
                </div>
                <div className="min-w-0 space-y-0.5">
                  <p className="font-medium leading-tight">{type.title}</p>
                  {type.tagline ? (
                    <p className="line-clamp-2 text-xs leading-5 text-muted-foreground">{type.tagline}</p>
                  ) : null}
                </div>
                {disabled && type.unavailable_reason ? (
                  <p className="mt-auto flex items-start gap-1 pt-1 text-xs leading-5 text-destructive">
                    <TriangleAlert className="mt-0.5 size-3 shrink-0" />
                    {type.unavailable_reason}
                  </p>
                ) : null}
              </button>
            );
          })}
        </div>
      ) : (
        <p className="rounded-lg border border-dashed bg-muted/30 px-3 py-6 text-center text-sm text-muted-foreground">
          {t("form.typeNoResults", { query })}
        </p>
      )}
    </div>
  );
}

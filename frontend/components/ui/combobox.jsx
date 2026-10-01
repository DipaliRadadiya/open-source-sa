import { useId, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Check, ChevronsUpDown, Search, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useChromeOffset } from "@/hooks/use-chrome-offset";

/**
 * Searchable single-select; use it instead of Select for long or data-driven
 * lists. Built on Popover without cmdk. `options` are `{ value, label, hint? }`;
 * `value`/`onChange` use the option value.
 */
export function Combobox({
  options = [],
  value,
  onChange,
  placeholder,
  searchPlaceholder,
  empty,
  disabled = false,
  // Forwarded to the trigger Button, which shows it.
  disabledReason,
  className,
  id,
  ariaLabel,
}) {
  const t = useTranslations("common");
  const [open, setOpen] = useState(false);
  // Keeps the popover clear of the sticky header; see hooks/use-chrome-offset.js.
  const [chromeOffset, measureChrome] = useChromeOffset();
  const [query, setQuery] = useState("");
  // The highlighted row for the keyboard, as an index into `filtered`.
  const [active, setActive] = useState(0);
  const listId = useId();
  const searchRef = useRef(null);
  const triggerRef = useRef(null);
  /*
   * Modal only inside a dialog: the Dialog's react-remove-scroll blocks wheel
   * events in the portalled popover, and a modal popover nests its own scroll
   * lock. On a normal page modal would block the rest of the screen.
   */
  const [modal, setModal] = useState(false);

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
    if (next) {
      measureChrome();
      setModal(Boolean(triggerRef.current?.closest('[role="dialog"]')));
    }
    setOpen(next);
    if (!next) setQuery("");
    setActive(0);
  }

  function choose(option) {
    if (!option || option.disabledReason) return;
    onChange?.(String(option.value));
    handleOpenChange(false);
  }

  // Enter picks the active (or first choosable) row; arrows skip blocked rows.
  function onSearchKeyDown(event) {
    if (event.key === "Enter") {
      event.preventDefault();
      const current = filtered[active];
      choose(current && !current.disabledReason ? current : filtered.find((option) => !option.disabledReason));
    } else if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!filtered.length) return;
      const step = event.key === "ArrowDown" ? 1 : -1;
      let next = active;
      for (let i = 0; i < filtered.length; i++) {
        next = (next + step + filtered.length) % filtered.length;
        if (!filtered[next].disabledReason) break;
      }
      setActive(next);
      document.getElementById(`${listId}-${next}`)?.scrollIntoView({ block: "nearest" });
    }
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange} modal={modal}>
      <PopoverTrigger asChild>
        <Button
          ref={triggerRef}
          type="button"
          id={id}
          variant="field"
          role="combobox"
          aria-label={ariaLabel}
          aria-expanded={open}
          disabled={disabled}
          disabledReason={disabledReason}
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
            onChange={(event) => {
              setQuery(event.target.value);
              setActive(0);
            }}
            onKeyDown={onSearchKeyDown}
            role="searchbox"
            aria-controls={listId}
            aria-activedescendant={filtered[active] ? `${listId}-${active}` : undefined}
            placeholder={searchPlaceholder ?? t("search")}
            className="h-9 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0 dark:bg-transparent"
          />
          {/* Clearing returns focus to the field for retyping. */}
          {query ? (
            <button
              type="button"
              onClick={() => {
                setQuery("");
                searchRef.current?.focus();
              }}
              aria-label={t("clearSearch")}
              className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:text-foreground"
            >
              <X className="size-4" />
            </button>
          ) : null}
        </div>
        <div id={listId} role="listbox" className="max-h-64 min-h-0 flex-1 overflow-y-auto p-1">
          {filtered.length ? (
            filtered.map((option, index) => {
              const isSelected = String(option.value) === String(value);
              // Blocked options are shown greyed with their reason, not hidden.
              const blocked = Boolean(option.disabledReason);
              return (
                <button
                  key={option.value}
                  id={`${listId}-${index}`}
                  type="button"
                  role="option"
                  aria-selected={isSelected}
                  tabIndex={-1}
                  disabled={blocked}
                  onClick={() => choose(option)}
                  onMouseEnter={() => setActive(index)}
                  className={cn(
                    "flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-sm",
                    blocked
                      ? "cursor-not-allowed opacity-60"
                      : "hover:bg-accent hover:text-accent-foreground",
                    isSelected && "bg-accent/60",
                    index === active && !blocked && "bg-accent text-accent-foreground",
                  )}
                >
                  <Check className={cn("mt-0.5 size-4 shrink-0", isSelected ? "opacity-100 text-primary" : "opacity-0")} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{option.label}</span>
                    {blocked ? (
                      <span className="block truncate text-xs font-medium text-warning">
                        {option.disabledReason}
                      </span>
                    ) : option.hint ? (
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

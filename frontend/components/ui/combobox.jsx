import { useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Check, ChevronsUpDown, Search, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useChromeOffset } from "@/hooks/use-chrome-offset";

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
  // Forwarded to the trigger, which is a Button and knows how to show it.
  // Without this a disabled Combobox was the one control that could never
  // explain itself, and it is the panel default for every long list.
  disabledReason,
  className,
  id,
  ariaLabel,
}) {
  const t = useTranslations("common");
  const [open, setOpen] = useState(false);
  // The sticky header is not empty space, whatever the positioning engine
  // thinks — see hooks/use-chrome-offset.js.
  const [chromeOffset, measureChrome] = useChromeOffset();
  const [query, setQuery] = useState("");
  const searchRef = useRef(null);
  const triggerRef = useRef(null);
  /*
   * Whether this picker lives inside a dialog.
   *
   * A Dialog wraps its content in react-remove-scroll, which blocks wheel
   * events on everything it does not consider inside itself. The popover is
   * portalled to the body, so it is "outside" — and the list scrolled with the
   * scrollbar or the keyboard but sat dead under a mouse wheel, which is how
   * everyone actually scrolls a dropdown.
   *
   * `modal` makes the popover manage its own scroll lock, which nests inside
   * the dialog's and lets its own content scroll. Only inside a dialog: on an
   * ordinary page a modal popover would block the rest of the screen for a
   * control that has no business doing that.
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
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange} modal={modal}>
      <PopoverTrigger asChild>
        <Button
          ref={triggerRef}
          type="button"
          id={id}
          variant="outline"
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
            onChange={(event) => setQuery(event.target.value)}
            placeholder={searchPlaceholder ?? t("search")}
            className="h-9 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0 dark:bg-transparent"
          />
          {/* The same clear the panel's other two search boxes have. This one
              is shared by the repository, branch, application and destination
              pickers, so its absence was felt in several places at once —
              and a filtered list with no matches gave no way back except
              selecting the text and deleting it.

              Focus returns to the field: the point of clearing is to type
              again, and the list underneath has just changed. */}
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
        <div className="max-h-64 min-h-0 flex-1 overflow-y-auto p-1">
          {filtered.length ? (
            filtered.map((option) => {
              const isSelected = String(option.value) === String(value);
              // Shown greyed with the reason rather than hidden: an option that
              // silently is not there reads as a bug in the list, and the
              // reason is usually the next thing the reader has to act on.
              const blocked = Boolean(option.disabledReason);
              return (
                <button
                  key={option.value}
                  type="button"
                  disabled={blocked}
                  onClick={() => {
                    if (blocked) return;
                    onChange?.(String(option.value));
                    handleOpenChange(false);
                  }}
                  className={cn(
                    "flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-sm",
                    blocked
                      ? "cursor-not-allowed opacity-60"
                      : "hover:bg-accent hover:text-accent-foreground",
                    isSelected && "bg-accent/60",
                  )}
                >
                  <Check className={cn("mt-0.5 size-4 shrink-0", isSelected ? "opacity-100 text-primary" : "opacity-0")} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{option.label}</span>
                    {/* The blocker replaces the hint: while the option cannot be
                        chosen, why is the only thing worth the line. */}
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

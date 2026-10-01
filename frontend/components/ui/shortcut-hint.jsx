import { useSyncExternalStore } from "react";
import { cn } from "@/lib/utils";

// The platform never changes, so there is nothing to subscribe to.
const noop = () => () => {};
const isMac = () =>
  /mac/i.test(navigator.userAgentData?.platform ?? navigator.platform ?? "");
// Server snapshot: Ctrl is the safer guess.
const notMac = () => false;

// Decorative only; the shortcut itself is bound elsewhere.
export function ShortcutHint({ letter, className }) {
  // useSyncExternalStore gives React an explicit server snapshot for hydration.
  const mac = useSyncExternalStore(noop, isMac, notMac);

  return (
    <kbd
      // TooltipContent styles `data-slot=kbd`.
      data-slot="kbd"
      aria-hidden="true"
      className={cn(
        "hidden select-none items-center gap-0.5 rounded border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium text-muted-foreground sm:inline-flex",
        className,
      )}
    >
      {/* "⌘S" on a Mac, "Ctrl+S" elsewhere. */}
      {mac ? "⌘" : "Ctrl"}
      {mac ? null : <span className="opacity-60">+</span>}
      {letter}
    </kbd>
  );
}

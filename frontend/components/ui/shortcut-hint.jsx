import { useSyncExternalStore } from "react";
import { cn } from "@/lib/utils";

const noop = () => () => {};
const isMac = () =>
  /mac/i.test(navigator.userAgentData?.platform ?? navigator.platform ?? "");
const notMac = () => false;

export function ShortcutHint({ letter, className }) {
  const mac = useSyncExternalStore(noop, isMac, notMac);

  return (
    <kbd
      data-slot="kbd"
      aria-hidden="true"
      className={cn(
        "hidden select-none items-center gap-0.5 rounded border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium text-muted-foreground sm:inline-flex",
        className,
      )}
    >
      {mac ? "⌘" : "Ctrl"}
      {mac ? null : <span className="opacity-60">+</span>}
      {letter}
    </kbd>
  );
}

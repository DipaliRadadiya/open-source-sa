import { useSyncExternalStore } from "react";
import { cn } from "@/lib/utils";

// Never changes for the life of the page, so there is nothing to subscribe to.
const noop = () => () => {};
const isMac = () =>
  /mac/i.test(navigator.userAgentData?.platform ?? navigator.platform ?? "");
// The server has no platform to read, and Ctrl is the safer guess: showing a
// key most keyboards do not have is worse than briefly showing one they do.
const notMac = () => false;

/**
 * The keyboard shortcut for the control it sits beside.
 *
 * Both file editors already saved on Cmd/Ctrl+S and neither said so, which
 * makes the shortcut worth nothing to everyone who has not tried it — and on a
 * page where the browser's own Ctrl+S does something else entirely, trying it
 * is not an obvious thing to risk.
 *
 * Decorative: the control it labels already names itself, so a screen reader
 * that reads "Save · Ctrl S" is being read punctuation. The shortcut itself is
 * bound by the editor, not here.
 *
 * Hidden below `sm`. A phone has no Ctrl key and the hint is pure noise there.
 */
export function ShortcutHint({ letter, className }) {
  // useSyncExternalStore rather than state-in-an-effect: it takes an explicit
  // server snapshot, so React does the hydration handoff itself instead of us
  // rendering the wrong key and correcting it afterwards.
  const mac = useSyncExternalStore(noop, isMac, notMac);

  return (
    <kbd
      aria-hidden="true"
      className={cn(
        "hidden select-none items-center gap-0.5 rounded border bg-muted px-1.5 py-0.5 font-mono text-[11px] font-medium text-muted-foreground sm:inline-flex",
        className,
      )}
    >
      {/* The symbol carries no space after it on a Mac — "⌘S", not "⌘ S". */}
      {mac ? "⌘" : "Ctrl"}
      {mac ? null : <span className="opacity-60">+</span>}
      {letter}
    </kbd>
  );
}

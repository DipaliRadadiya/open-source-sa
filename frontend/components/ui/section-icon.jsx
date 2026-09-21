import { cn } from "@/lib/utils";

/**
 * The icon beside a section heading.
 *
 * Krishna: make it read like "a professional server-management product, not an
 * AI-generated SaaS template". This is the single clearest tell. The panel had
 * 60 tinted rounded squares across 51 files — `bg-primary/10 text-primary` in
 * nine different sizes — and every section wore one, so the brand colour was
 * decoration rather than meaning. On the dashboard alone it put a blue tile on
 * CPU, Memory, Swap, Disk, Load avg, Network I/O and Disk I/O, none of which
 * is an action and none of which is more important than the numbers beside it.
 *
 * The icon stays. The tile goes. It inherits `text-muted-foreground`, so the
 * heading beside it is what carries the weight — which is the whole point of
 * giving cards a real type scale first.
 *
 * NOT applied to every `bg-primary/10` in the panel, on purpose. An empty
 * state's hero mark is a deliberate focal point with nothing to compete with,
 * the user menu's initials are an avatar, and a progress track needs a tint to
 * be a track. Those keep theirs; a heading ornament does not.
 */
export function SectionIcon({ icon: Icon, className }) {
  return (
    <Icon
      aria-hidden
      className={cn("size-4 shrink-0 text-muted-foreground", className)}
    />
  );
}

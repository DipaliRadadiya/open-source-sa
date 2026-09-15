import { Globe2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";

/**
 * The application's logo, wherever an application is shown.
 *
 * One component for the list, the cards, the sidebar and the create picker,
 * because the alternative is four copies of "is there a logo for this, and
 * what do I draw when there is not" — and the fourth copy is the one that
 * forgets the fallback and renders a broken image.
 *
 * Keyed on the site type's `name`, which is what both payloads carry: the
 * picker has it on the type, the list has it on the application as
 * `site_type`.
 *
 * No tile behind it. The logos bring their own colour and shape, and a tinted
 * square around each one turns a list of logos into a list of boxes — see the
 * same decision in the picker. The fallback keeps the tile, because a lone
 * grey glyph floating in a row has nothing to hold it.
 */
/*
 * Constrained by HEIGHT, not by a square box.
 *
 * A square box makes the two shapes disagree: Akaunting and Craft are square,
 * so they fill all of it and set the row's floor, while Moodle's 80×21
 * wordmark uses a quarter of the same box and reads as a smudge. One is too
 * big and the other too small at the identical setting.
 *
 * Fixing the height and letting the width follow gives every logo the same
 * optical weight — the wide ones get wider rather than shorter — and the tile
 * can no longer be what makes a row tall, because 28px is under the two lines
 * of text beside it.
 */
export function SiteTypeLogo({ name, className, size = "h-7 w-auto max-w-12" }) {
  const logo = siteTypeLogo(name);

  if (logo) {
    return (
      // A local file a few KB in size, usually SVG — next/image cannot
      // optimise those without `dangerouslyAllowSVG`, and there is nothing
      // here for it to optimise.
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={logo}
        alt=""
        aria-hidden
        className={cn("shrink-0 object-contain", size, className)}
      />
    );
  }

  return (
    <span
      className={cn(
        "flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary",
        className,
      )}
    >
      <Globe2 className="size-4" />
    </span>
  );
}

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
 * size-10 by default, matching the create picker. At size-8 a wide mark —
 * Moodle's wordmark is 80×21, Nextcloud's 256×128 — contained down to about
 * ten pixels tall and read as a smudge. The list rows are 65px, so the larger
 * box costs no height.
 */
export function SiteTypeLogo({ name, className, size = "size-10" }) {
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
        "flex shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary",
        size,
        className,
      )}
    >
      <Globe2 className="size-4" />
    </span>
  );
}

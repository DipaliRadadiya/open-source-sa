import { Globe2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";

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
 * A FIXED-WIDTH SLOT, with the logo fitted inside it.
 *
 * Constrained by height alone — `h-7 w-auto` — every logo takes the width its
 * own aspect ratio asks for: Craft's square is 28px wide, Moodle's 80×21
 * wordmark is 48. In a list that is a ragged text column, because the name
 * beside each logo starts wherever that logo happened to end. Twenty pixels of
 * disagreement row to row is small enough to look like a rendering fault and
 * large enough to see.
 *
 * So the slot is a constant `h-7 w-12` and the image is fitted into it with
 * `max-h-full max-w-full object-contain`: the tall-and-square and the
 * wide-and-short both keep their proportions, neither is cropped, and the text
 * column starts at the same x on every row. Centred rather than flush left,
 * because a 28px mark pushed against the left edge of a 48px slot puts its
 * whitespace all on one side and reads as misaligned in the other direction.
 *
 * Sizing by height inside the slot stays right for the original reason: a
 * square box would make Akaunting and Craft fill it and set the row's floor
 * while Moodle used a quarter of it and read as a smudge.
 */
export function SiteTypeLogo({ name, provider, className, size = "h-7 w-12" }) {
  const logo = siteTypeLogo(name);

  return (
    <span className={cn("flex shrink-0 items-center justify-center", size, className)}>
      {/* A git site shows the service it came from, where that is known: every
          one of them is "From Git repo" with the same mark otherwise, and which
          service it is is the one thing that distinguishes them. GitHub's own
          logo is a black cat-octopus and GitLab's an orange fox — these are
          drawn in `currentColor` instead, because they sit in a list of
          full-colour brand marks and three more would make the column louder
          than the names beside it. Unknown provider falls through to the
          generic git mark, which is what every git row used to show. */}
      {provider ? (
        <ProviderLogo provider={provider} className="size-5" />
      ) : logo ? (
        // A local file a few KB in size, usually SVG — next/image cannot
        // optimise those without `dangerouslyAllowSVG`, and there is nothing
        // here for it to optimise.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={logo} alt="" aria-hidden className="max-h-full max-w-full object-contain" />
      ) : (
        // The fallback keeps its tile — a lone grey glyph floating in a row has
        // nothing to hold it — and takes the slot's height as a square, so it
        // occupies the same column as a logo instead of its own.
        <span className="flex aspect-square h-full items-center justify-center rounded-md bg-primary/10 text-primary">
          <Globe2 className="size-4" />
        </span>
      )}
    </span>
  );
}

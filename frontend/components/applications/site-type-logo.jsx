import { Globe2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";

/**
 * The application's logo, wherever an application is shown (list, cards,
 * sidebar, create picker), with one fallback. Keyed on the site type `name`
 * (`site_type` on an application).
 *
 * Logos sit in a fixed `h-7 w-12` slot, fitted with `object-contain` and
 * centred, so square marks and wide wordmarks keep their proportions and the
 * text beside them starts at the same x on every row. No tile behind logos;
 * the fallback glyph keeps one.
 *
 * `label` names the type, only for callers where the mark is the sole
 * indication (the applications table). Elsewhere the type is printed beside
 * it, so a label would be read twice. Most marks do not spell their name.
 */
export function SiteTypeLogo({ name, provider, className, size = "h-7 w-12", label = null }) {
  const logo = siteTypeLogo(name);

  return (
    <span
      className={cn("flex shrink-0 items-center justify-center", size, className)}
      title={label ?? undefined}
      // The name goes on the wrapper, not the <img>: two of the three branches below
      // are not images. The image stays aria-hidden so nothing is announced twice.
      role={label ? "img" : undefined}
      aria-label={label ?? undefined}
    >
      {/* A git site shows its provider's mark when known (drawn `mono` so it does
          not outshout the names); otherwise the generic git mark. */}
      {provider ? (
        <ProviderLogo provider={provider} className="size-5" mono />
      ) : logo ? (
        // Small local files, usually SVG; next/image cannot optimise SVG without
        // `dangerouslyAllowSVG`, and there is nothing to optimise.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={logo} alt="" aria-hidden className="max-h-full max-w-full object-contain" />
      ) : (
        // The fallback keeps a square tile at the slot's height, so it aligns with
        // the logos.
        <span className="flex aspect-square h-full items-center justify-center rounded-md bg-primary/10 text-primary">
          <Globe2 className="size-4" />
        </span>
      )}
    </span>
  );
}

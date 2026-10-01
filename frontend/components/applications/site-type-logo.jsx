import { Globe2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";

// Fixed `h-7 w-12` slot so text beside the logo starts at the same x on every row.
// `label` only where the mark is the sole indication; most marks do not spell their name.
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
        // Square tile at the slot's height, so it aligns with the logos.
        <span className="flex aspect-square h-full items-center justify-center rounded-md bg-primary/10 text-primary">
          <Globe2 className="size-4" />
        </span>
      )}
    </span>
  );
}

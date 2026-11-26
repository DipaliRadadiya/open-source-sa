import { Globe2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { siteTypeLogo } from "@/lib/applications/site-type-logo";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { HoverHint } from "@/components/ui/hover-hint";

export function SiteTypeLogo({ name, provider, iconDataUri, className, size = "h-7 w-12", label = null }) {
  const logo = iconDataUri || siteTypeLogo(name);

  return (
    <HoverHint content={label ?? undefined}><span
      className={cn("flex shrink-0 items-center justify-center", size, className)}
      role={label ? "img" : undefined}
      aria-label={label ?? undefined}
    >
      {provider && !iconDataUri ? (
        <ProviderLogo provider={provider} className="size-5" mono />
      ) : logo ? (
        // Catalog data URIs and small local SVGs need no optimisation.
        // next/image cannot optimise SVG without
        // `dangerouslyAllowSVG`, and there is nothing to optimise.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={logo} alt="" aria-hidden className="max-h-full max-w-full object-contain" />
      ) : (
        <span className="flex aspect-square h-full items-center justify-center rounded-md bg-primary/10 text-primary">
          <Globe2 className="size-4" />
        </span>
      )}
    </span></HoverHint>
  );
}

import { Database } from "lucide-react";

import { cn } from "@/lib/utils";
import { engineLogo } from "@/lib/databases/engine-logo";

/**
 * A database engine's logo, swapping variant with the theme.
 *
 * Both images render and CSS shows one: the theme class is set on `<html>`
 * before paint, so choosing in JS would flash on dark-mode loads. Sized by
 * height because these are wide lockups. No fixed-width slot: the engine name
 * beside it is `sr-only`, so a varying width moves no text.
 */
export function EngineLogo({ engine, className, size = "h-5 w-auto max-w-20" }) {
  const logo = engineLogo(engine);

  if (!logo) {
    return <Database className={cn("size-4 shrink-0 text-muted-foreground", className)} aria-hidden />;
  }

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={logo.light}
        alt=""
        aria-hidden
        className={cn("shrink-0 object-contain dark:hidden", logo.size ?? size, className)}
      />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={logo.dark}
        alt=""
        aria-hidden
        className={cn(
          "hidden shrink-0 object-contain dark:block",
          logo.darkSize ?? size,
          className,
        )}
      />
    </>
  );
}

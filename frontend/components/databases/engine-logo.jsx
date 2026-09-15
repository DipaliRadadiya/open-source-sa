import { Database } from "lucide-react";

import { cn } from "@/lib/utils";
import { engineLogo } from "@/lib/databases/engine-logo";

/**
 * A database engine's logo, swapping variant with the theme.
 *
 * Both images are rendered and CSS shows one, rather than reading the theme in
 * JavaScript: the theme is applied by a class on `<html>` before paint, so a
 * component that decided in JS would render the light logo on the server, the
 * dark one after hydration, and flash on every load of a dark-mode page.
 *
 * Sized by height for the reason the application logos are: these are wide
 * lockups — MongoDB's is 1102×278 — and a square box would shrink them to a
 * smear while a square mark filled it.
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

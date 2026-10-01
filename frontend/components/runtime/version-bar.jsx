"use client";

import { useEffect, useRef } from "react";
import Link from "@/components/ui/app-link";
import { usePathname, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { compareVersions } from "@/lib/runtime/version-range";

/**
 * Which version the rest of the page is about. Shared by PHP and Node.
 *
 * Only rendered with more than one version. The selection lives in the URL so
 * it survives a reload and is linkable.
 */
export function VersionBar({
  versions,
  selected,
  namespace,
  lifecycleAvailable = false,
  // Rendered at the end of the chips, in the same scrolling row.
  action,
}) {
  const t = useTranslations(namespace);
  const pathname = usePathname();
  const searchParams = useSearchParams();
  // Newest first: the API lists in-flight installs ahead of installed versions.
  const ordered = [...versions].sort((a, b) => compareVersions(b.version, a.version));
  const navRef = useRef(null);

  // On a phone the strip scrolls; keep the selected chip fully in view.
  useEffect(() => {
    navRef.current
      ?.querySelector('[aria-current="page"]')
      ?.scrollIntoView({ block: "nearest", inline: "nearest" });
  }, [selected]);

  return (
    <div className="space-y-2">
      {/* Labelled, so the chips do not read as a heading. */}
      <p className="text-sm font-medium">{t("versions.switchLabel")}</p>

      <ScrollFade className="-mx-1 px-1 pb-1">
        <nav ref={navRef} aria-label={t("versions.pickerLabel")} className="flex w-fit items-center gap-2">
          {ordered.map((version) => {
            const active = version.version === selected;
            const params = new URLSearchParams(searchParams);
            params.set("version", version.version);
            return (
              <Link
                key={version.version}
                href={`${pathname}?${params.toString()}`}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors",
                  active
                    ? "border-primary bg-primary/5 text-foreground"
                    : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                )}
              >
                {t("versions.name", { version: version.version })}
                {/* Only end-of-life versions are flagged here. */}
                {version.lifecycle?.status === "eol" ? (
                  <LifecycleBadge
                    lifecycle={version.lifecycle}
                    namespace={namespace}
                    available={lifecycleAvailable}
                  />
                ) : null}
              </Link>
            );
          })}
          {action ? <div className="ms-1 shrink-0">{action}</div> : null}
        </nav>
      </ScrollFade>
    </div>
  );
}

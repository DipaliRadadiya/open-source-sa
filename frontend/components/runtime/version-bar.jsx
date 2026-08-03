"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { ScrollFade } from "@/components/ui/scroll-fade";

/**
 * Which version the rest of the page is about. Shared by PHP and Node.
 *
 * Only rendered when there is more than one version — on a one-version server
 * there is nothing to switch, and a control with a single option reads as a
 * step you must complete before the page will work.
 *
 * The selection lives in the URL, not in state: the page is rendered on the
 * server, and the version you were looking at should survive a reload and be
 * linkable.
 */
export function VersionBar({ versions, selected, namespace, lifecycleAvailable = false }) {
  const t = useTranslations(namespace);
  const pathname = usePathname();
  const searchParams = useSearchParams();

  return (
    <div className="space-y-2">
      {/* The label is the whole point: an unlabelled row of version chips looks
          like a heading, and nobody clicks a heading. */}
      <p className="text-sm font-medium">{t("versions.switchLabel")}</p>

      <ScrollFade className="-mx-1 px-1 pb-1">
        <nav aria-label={t("versions.pickerLabel")} className="flex w-fit items-center gap-2">
          {versions.map((version) => {
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
                {/* Only the dead one is flagged while choosing — a badge on every
                    chip is noise, and this is the one worth noticing. */}
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
        </nav>
      </ScrollFade>
    </div>
  );
}

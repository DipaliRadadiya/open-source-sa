"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { isInFlight } from "@/lib/runtime/in-flight";

/**
 * One PHP version's sections as tabs, like `DatabaseTabs`, so neither section
 * is buried below the ~96-row extensions list.
 *
 * ionCube stays separate from the extensions list: it is a vendor `.so`, not an
 * apt package, and can be unavailable for a PHP version. php.ini stays a button
 * on the version header (it is a dialog). The tab label carries ionCube's state.
 */

const VALUES = ["extensions", "ioncube"];

/**
 * ionCube's state as one short word for the tab.
 *
 * Uses `isInFlight` (shared with the card), never a hand-written test: the API
 * sends `idle` for untouched servers, which is not in flight.
 */
function ionCubeBadge(ioncube, failed, t) {
  if (failed || !ioncube) return null;
  // An older version installed it on 7.4, so unsupported can still be running.
  if (!ioncube.supported && !ioncube.installed) return { label: t("ioncube.unavailableShort"), variant: "outline" };
  if (ioncube.status === "failed") return { label: t("ioncube.failedShort"), variant: "destructive" };
  if (isInFlight(ioncube.status)) {
    // Removing is in flight too; label it as such, not "Installing".
    const key = ioncube.status === "removing" ? "removingShort" : "installingShort";
    return { label: t(`ioncube.${key}`), variant: "warning" };
  }
  return ioncube.installed
    ? { label: t("ioncube.onShort"), variant: "success" }
    : { label: t("ioncube.offShort"), variant: "outline" };
}

export function PhpVersionTabs({
  extensions,
  ioncube,
  extensionCount,
  ionCubeState,
  ionCubeFailed = false,
  initial,
}) {
  const t = useTranslations("php");
  const [tab, setTab] = useState(() => (VALUES.includes(initial) ? initial : "extensions"));

  const badge = ionCubeBadge(ionCubeState, ionCubeFailed, t);

  const sections = [
    {
      value: "extensions",
      label: t("tabs.extensions"),
      badge:
        typeof extensionCount === "number"
          ? { label: String(extensionCount), variant: "secondary" }
          : null,
      node: extensions,
    },
    { value: "ioncube", label: t("tabs.ioncube"), badge, node: ioncube },
  ];

  function select(next) {
    setTab(next);
    // Same as DatabaseTabs: a URL rewrite rather than a route change, so Back
    // does not step through tabs.
    const url = new URL(window.location.href);
    url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  }

  return (
    <div className="space-y-4">
      <Tabs value={tab} onValueChange={select} className="gap-4">
        {/* Scrolls rather than wraps, matching the other tab strips. */}
        <ScrollFade className="-mx-1 px-1 pb-1">
          <TabsList className="!h-auto w-fit gap-1 p-1">
            {sections.map((section) => (
              <TabsTrigger
                key={section.value}
                value={section.value}
                className="!h-auto gap-2 px-4 py-2"
              >
                {section.label}
                {section.badge ? (
                  <Badge
                    variant={section.badge.variant}
                    className="ml-1.5 font-normal tabular-nums"
                  >
                    {section.badge.label}
                  </Badge>
                ) : null}
              </TabsTrigger>
            ))}
          </TabsList>
        </ScrollFade>

        {/* Inside real tab panels so each tab's aria-controls resolves. Only
            the active panel mounts. */}
        {sections.map((section) => (
          <TabsContent key={section.value} value={section.value}>
            {section.node}
          </TabsContent>
        ))}
      </Tabs>


    </div>
  );
}

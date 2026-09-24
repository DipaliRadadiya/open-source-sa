"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { isInFlight } from "@/lib/runtime/in-flight";

/**
 * One PHP version's sections, one at a time.
 *
 * They were two stacked cards, extensions first. That card is a list of ~96
 * rows capped at 32rem, so everything after it started around 1100px down the
 * page — and what happened to be after it was ionCube, which is a thing people
 * come to this page looking for rather than something they should scroll into.
 * Reported exactly that way.
 *
 * Moving ionCube above the list was the first attempt and it was no answer:
 * whichever section goes second is the one that disappears, so the order only
 * decides which thing gets buried.
 *
 * Tabs are what the field does with several per-version concerns — aaPanel
 * gives a version Service / Extension / Disabled Functions / Common Parameters
 * / Log, and Plesk pairs extension checkboxes with a php.ini tab. Same shape as
 * `DatabaseTabs` here, for the same reason it was built.
 *
 * ionCube is NOT folded into the extensions list, though it would shorten the
 * strip. Every row there is an apt package toggled with phpenmod; ionCube is a
 * vendor `.so` declared as a `zend_extension`, and it has a state no extension
 * row can express — ionCube publishes no loader for some PHP versions at all.
 *
 * php.ini stays a button on the version header rather than becoming a third
 * tab: it is a dialog, and the research is consistent that php.ini is a
 * secondary surface reached FROM a version.
 *
 * The state sits in the label for the reason DatabaseTabs puts counts there —
 * a tab strip otherwise costs you the ability to see a section without opening
 * it, and "is ionCube on" is the whole question someone came to ask.
 */

const VALUES = ["extensions", "ioncube"];

/**
 * ionCube's state as one short word for the tab.
 *
 * `isInFlight` decides what "running" means, NOT a hand-written test. The first
 * version of this asked `status && status !== "ready"` and so read `idle` — the
 * value the API sends when ionCube has never been touched, which is most
 * servers — as an install in progress. The whole strip said "Installing" on a
 * page where nothing was happening.
 *
 * The vocabulary is `installing | removing | ready | failed | idle` and
 * `isInFlight` already encodes which of those mean in-flight; the card beside
 * this uses it too, so the tab and the card cannot disagree.
 */
function ionCubeBadge(ioncube, failed, t) {
  if (failed || !ioncube) return null;
  // v7 installed it on 7.4, so unsupported can still be running.
  if (!ioncube.supported && !ioncube.installed) return { label: t("ioncube.unavailableShort"), variant: "outline" };
  if (ioncube.status === "failed") return { label: t("ioncube.failedShort"), variant: "destructive" };
  if (isInFlight(ioncube.status)) {
    // Removing is in flight too, and calling it "Installing" is the same kind of
    // wrong thing this function already got caught doing once.
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
    // Same as DatabaseTabs: the sections are already on the page, so this is a
    // URL rewrite rather than a route change — Back returns to where you came
    // from instead of stepping through tabs.
    const url = new URL(window.location.href);
    url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  }

  return (
    <div className="space-y-4">
      <Tabs value={tab} onValueChange={select}>
        {/* Scrolls rather than wraps, matching the other tab strips: a bar that
            reflows to two rows stops reading as one control. */}
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
      </Tabs>

      {/* Rendered, not mounted per tab: both sections come from the server with
          the page, so switching is instant and nothing refetches. */}
      {sections.find((section) => section.value === tab)?.node}
    </div>
  );
}

"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";

/**
 * One database's sections, one at a time.
 *
 * They used to be four stacked cards. That reads fine with two users and two
 * backups, and falls apart the moment a database has twenty of either — tables
 * and backups end up below the fold, and someone who has not scrolled concludes
 * they do not exist. Tabs keep every section one click from the top of the
 * page regardless of how long any one of them gets.
 *
 * The counts live in the labels so you can see what is in each section without
 * opening it — the main thing a tab strip otherwise costs you.
 *
 * The open tab is written to the URL, so refreshing after an export keeps you
 * on Backups and a link to it opens where it was sent from. `replaceState`
 * rather than a route change: the sections are already on the page, so there is
 * nothing to re-fetch, and Back should return to the list rather than step
 * through tabs.
 */
const VALUES = ["users", "tables", "backups"];

export function DatabaseTabs({ users, tables, backups, counts, initial }) {
  const t = useTranslations("databases.tabs");
  const [tab, setTab] = useState(() =>
    VALUES.includes(initial) ? initial : "users",
  );

  const sections = [
    { value: "users", label: t("users"), count: counts.users, node: users },
    { value: "tables", label: t("tables"), count: counts.tables, node: tables },
    { value: "backups", label: t("backups"), count: counts.backups, node: backups },
  ];

  function select(next) {
    setTab(next);
    const url = new URL(window.location.href);
    url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  }

  return (
    <div className="space-y-4">
      <Tabs value={tab} onValueChange={select}>
        {/* Wraps rather than overflowing: three tabs with a count each are 7px
            too wide for a 390px phone, and `w-fit` on a non-wrapping row means
            the whole page scrolls sideways to hide it. */}
        <TabsList className="!h-auto w-fit flex-wrap gap-1 p-1">
          {sections.map((section) => (
            <TabsTrigger
              key={section.value}
              value={section.value}
              className="!h-auto gap-2 px-4 py-2"
            >
              {section.label}
              {/* Zero is worth showing too: "Users 0" is the fact that nothing
                  can connect, which is exactly what someone needs to see. */}
              <Badge variant="secondary" className="ml-1.5 font-normal tabular-nums">
                {section.count}
              </Badge>
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {/* Rendered, not mounted per tab: the sections are already on the page
          from the server, so switching is instant and nothing refetches. */}
      {sections.find((section) => section.value === tab)?.node}
    </div>
  );
}

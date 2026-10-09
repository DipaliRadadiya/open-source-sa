"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

// `replaceState`, not a route change: sections are loaded, and Back should return to the list.
const VALUES = ["overview", "users", "tables", "exports"];
// Old tab values still appear in saved links.
const LEGACY = { backups: "exports" };

export function DatabaseTabs({ overview, users, tables, exports: exportsNode, counts, initial }) {
  const t = useTranslations("databases.tabs");
  const [tab, setTab] = useState(() => {
    const wanted = LEGACY[initial] ?? initial;
    return VALUES.includes(wanted) ? wanted : "overview";
  });

  const sections = [
    // Connection details and the facts first: connecting an application is the main task.
    { value: "overview", label: t("overview"), count: null, node: overview },
    { value: "users", label: t("users"), count: counts.users, node: users },
    { value: "tables", label: t("tables"), count: counts.tables, node: tables },
    { value: "exports", label: t("exports"), count: counts.exports, node: exportsNode },
  ];

  function select(next) {
    setTab(next);
    const url = new URL(window.location.href);
    url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  }

  return (
    <div className="space-y-4">
      <Tabs value={tab} onValueChange={select} className="gap-4">
        {/* Scrolls rather than wraps, like the Settings tab bar; ScrollFade
            signals more content to the side. */}
        <ScrollFade className="-mx-1 px-1 pb-1">
          <TabsList className="!h-auto w-fit gap-1 p-1">
            {sections.map((section) => (
              <TabsTrigger
                key={section.value}
                value={section.value}
                className="!h-auto gap-2 px-4 py-2"
              >
                {section.label}
                {/* Zero is shown too: "Users 0" means nothing can connect. */}
                {section.count === null ? null : (
                  <Badge variant="secondary" className="ml-1.5 font-normal tabular-nums">
                    {section.count}
                  </Badge>
                )}
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

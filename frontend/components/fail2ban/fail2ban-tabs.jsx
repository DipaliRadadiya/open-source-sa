"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { ShieldCheck, SlidersHorizontal } from "lucide-react";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";

const TRIGGER = "gap-2 px-3 py-1.5";

// `forceMount` keeps unsaved edits across tab switches. An unignored own IP
// marks the settings trigger so the lockout risk does not hide behind a tab.
export function Fail2banTabs({ live, settings, ignoreIps = [], status }) {
  const t = useTranslations("fail2ban");
  const yourIp = useBrowserIp();
  const needsAttention = Boolean(yourIp && !ignoreIps.includes(yourIp));
  const [tab, setTab] = useState("live");

  return (
    <Tabs value={tab} onValueChange={setTab} className="gap-4">
      {/* The unhealthy state stays a full-width alert above. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* Scrolls rather than wraps, like the Settings tab bar. */}
        <ScrollFade className="-mx-1 px-1 pb-1">
          <TabsList className="!h-auto w-fit gap-1 p-1">
            <TabsTrigger value="live" className={TRIGGER}>
              <ShieldCheck className="size-4" />
              {t("tabs.live")}
            </TabsTrigger>
            <TabsTrigger value="settings" className={TRIGGER}>
              <SlidersHorizontal className="size-4" />
              {t("tabs.settings")}
              {needsAttention ? (
                <span
                  className="size-1.5 rounded-full bg-warning"
                  aria-label={t("tabs.needsAttention")}
                />
              ) : null}
            </TabsTrigger>
          </TabsList>
        </ScrollFade>
        {status}
      </div>

      <TabsContent value="live" forceMount className="space-y-4 data-[state=inactive]:hidden">
        {live}
      </TabsContent>

      <TabsContent value="settings" forceMount className="data-[state=inactive]:hidden">
        {settings}
      </TabsContent>
    </Tabs>
  );
}

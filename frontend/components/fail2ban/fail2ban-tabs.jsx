"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ShieldCheck, SlidersHorizontal } from "lucide-react";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";

const TRIGGER = "gap-2 px-3 py-1.5";

/**
 * Splits the page into what is happening now and what you configured once.
 *
 * Everything on one screen meant five blocks competing at first glance, and
 * four of them are things you read rather than act on. Jails and bans are the
 * live picture; the rules and the ignore list are settings you set and forget.
 *
 * Both panels stay mounted (`forceMount`, hidden by state) so half-typed
 * settings survive a trip to the other tab — an unmounting tab silently
 * discards edits, which is the same class of bug as a dialog that closes itself.
 *
 * The one thing that must NOT hide behind a tab is the lockout risk, so an
 * unignored address puts a marker on the settings trigger.
 */
export function Fail2banTabs({ live, settings, needsAttention, status }) {
  const t = useTranslations("fail2ban");
  const [tab, setTab] = useState("live");

  return (
    <Tabs value={tab} onValueChange={setTab} className="gap-4">
      {/* Status rides along the tab row rather than owning a row of its own —
          "everything is fine" does not deserve its own band. The unhealthy
          state is not passed here; it stays a full-width alert above. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* Scrolls rather than wraps, same as the Settings tab bar: a bar that
            reflows to two rows stops reading as one control. ScrollFade is what
            says there is more to the side. */}
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

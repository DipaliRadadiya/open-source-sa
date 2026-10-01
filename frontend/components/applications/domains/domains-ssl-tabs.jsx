"use client";

import { useState, useCallback } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Globe2, Lock, ShieldAlert, ShieldOff, ShieldQuestion, Loader2 } from "lucide-react";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";

const TABS = ["domains", "ssl"];
// !h-auto overrides shadcn TabsList's hard-coded height so the py padding lands.
const TRIGGER = "!h-auto gap-2 px-4 py-2";

// The SSL tab icon shows the security status without opening the tab.
function SslIcon({ status, label }) {
  // Certificate read failed: show "unknown", never "not secured".
  if (status === "unknown")
    return <ShieldQuestion className="size-4 text-muted-foreground" aria-label={label} />;
  if (status === "active") return <Lock className="size-4 text-success" aria-label={label} />;
  if (status === "issuing")
    return <Loader2 className="size-4 animate-spin text-primary" aria-label={label} />;
  if (status === "failed")
    return <ShieldAlert className="size-4 text-destructive" aria-label={label} />;
  return <ShieldOff className="size-4 text-muted-foreground" aria-label={label} />;
}

// Both panels stay mounted (`forceMount`) so an in-flight certificate issue
// keeps polling across tab switches.
export function DomainsSslTabs({ domains, ssl, sslStatus = "none" }) {
  const t = useTranslations("applications.domains");
  const searchParams = useSearchParams();
  const initial = TABS.includes(searchParams.get("tab")) ? searchParams.get("tab") : "domains";
  const [tab, setTab] = useState(initial);

  const onChange = useCallback((next) => {
    setTab(next);
    const params = new URLSearchParams(window.location.search);
    params.set("tab", next);
    window.history.replaceState(null, "", `?${params.toString()}`);
  }, []);

  const sslLabel =
    sslStatus === "unknown"
      ? t("tabs.sslUnknown")
      : sslStatus === "active"
        ? t("tabs.secured")
        : t("tabs.notSecured");

  return (
    <Tabs value={tab} onValueChange={onChange} className="gap-4">
      {/* Scrolls rather than wraps, like the Settings tab bar. */}
      <ScrollFade className="-mx-1 px-1 pb-1">
        <TabsList className="!h-auto w-fit gap-1 p-1">
          <TabsTrigger value="domains" className={TRIGGER}>
            <Globe2 className="size-4" />
            {t("tabs.domains")}
          </TabsTrigger>
          <TabsTrigger value="ssl" className={TRIGGER}>
            <SslIcon status={sslStatus} label={sslLabel} />
            {t("tabs.ssl")}
          </TabsTrigger>
        </TabsList>
      </ScrollFade>

      <TabsContent value="domains" forceMount className="data-[state=inactive]:hidden">
        {domains}
      </TabsContent>
      <TabsContent value="ssl" forceMount className="data-[state=inactive]:hidden">
        {ssl}
      </TabsContent>
    </Tabs>
  );
}

"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { RecommendedSetup } from "@/components/fail2ban/recommended-setup";
import { JailsCard } from "@/components/fail2ban/jails-card";
import { BannedCard } from "@/components/fail2ban/banned-card";

// fail2ban still reports bans for a while after a jail is turned off.
export function ProtectionSection({ jails, settings, banned, ignoreIps, canManage, logHref, serverIp = null }) {
  const yourIp = useBrowserIp();
  const [asked, setAsked] = useState({});

  const shownJails = jails.map((jail) => {
    const override = asked[jail.name];
    return override && override.from === jail.enabled
      ? { ...jail, enabled: override.value }
      : jail;
  });

  return (
    <>
      <RecommendedSetup
        jails={jails}
        settings={settings}
        yourIp={yourIp}
        ignoreIps={ignoreIps}
        canManage={canManage}
      />

      <JailsCard
        jails={jails}
        settings={settings}
        yourIp={yourIp}
        ignoreIps={ignoreIps}
        canManage={canManage}
        asked={asked}
        onAskedChange={setAsked}
      />

      {shownJails.some((jail) => jail.enabled) ||
      (banned.length > 0 && Object.keys(asked).length === 0) ? (
        <NavTransitionProvider>
          <BannedCard banned={banned} jails={jails} canManage={canManage} logHref={logHref} yourIp={yourIp} serverIp={serverIp} />
        </NavTransitionProvider>
      ) : null}
    </>
  );
}

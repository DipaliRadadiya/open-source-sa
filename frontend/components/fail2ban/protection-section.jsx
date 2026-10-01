"use client";

import { useBrowserIp } from "@/components/network/browser-ip";
import { useState } from "react";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { RecommendedSetup } from "@/components/fail2ban/recommended-setup";
import { JailsCard } from "@/components/fail2ban/jails-card";
import { BannedCard } from "@/components/fail2ban/banned-card";

// One component so the jail switches and ban list cannot disagree: fail2ban still
// reports bans for a while after a jail is off.
export function ProtectionSection({ jails, settings, banned, ignoreIps, canManage, logHref, serverIp = null }) {
  const yourIp = useBrowserIp();
  // name -> requested enabled value plus the server value it was based on, so
  // it retires itself once the refresh lands.
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

      {/* Shown only when a jail is on or bans exist. The provider dims the
          table while the page re-reads fail2ban after a ban or unban. */}
      {shownJails.some((jail) => jail.enabled) ||
      (banned.length > 0 && Object.keys(asked).length === 0) ? (
        <NavTransitionProvider>
          <BannedCard banned={banned} jails={jails} canManage={canManage} logHref={logHref} yourIp={yourIp} serverIp={serverIp} />
        </NavTransitionProvider>
      ) : null}
    </>
  );
}

"use client";

import { useState } from "react";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { RecommendedSetup } from "@/components/fail2ban/recommended-setup";
import { JailsCard } from "@/components/fail2ban/jails-card";
import { BannedCard } from "@/components/fail2ban/banned-card";

/**
 * The live half of the page, kept in one client component so the jail switches
 * and the ban list cannot disagree.
 *
 * Whether "Banned addresses" appears is decided by the jails: nothing can be
 * banned by a jail that is off. That answer used to come from the server data
 * alone while the switches showed what you had just asked for — so switching
 * the last jail off left a list of live bans sitting under a row of off
 * switches for as long as it took `router.refresh()` to catch fail2ban up
 * (fail2ban has to release the bans first, so the immediate refresh still
 * returns them, and it was really the 10s auto-refresh that cleared it).
 * Reading the same optimistic state the switches read makes the card go when
 * the switch moves.
 */
export function ProtectionSection({ jails, settings, banned, yourIp, ignoreIps, canManage, logHref }) {
  // name -> the enabled value the user asked for, paired with the server value
  // it was based on so it retires itself once the refresh lands.
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

      {/* Nothing can be banned by a jail that is off, so on a fresh install this
          card is a box explaining that a thing which cannot have happened has
          not happened. It appears once the server is actually watching — or if
          bans already exist. */}
      {/* Banning and unbanning both change this list on the server, and the row
          only appears once the page has re-read fail2ban. The provider is what
          lets the table dim in the meantime — the same signal every other list
          in the panel uses while it re-fetches — instead of sitting there
          looking like the ban did nothing. */}
      {shownJails.some((jail) => jail.enabled) ||
      (banned.length > 0 && Object.keys(asked).length === 0) ? (
        <NavTransitionProvider>
          <BannedCard banned={banned} jails={jails} canManage={canManage} logHref={logHref} yourIp={yourIp} />
        </NavTransitionProvider>
      ) : null}
    </>
  );
}

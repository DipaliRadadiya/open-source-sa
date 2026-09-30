"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { getFirewall } from "@/lib/api/firewall";
import { getFail2ban } from "@/lib/api/fail2ban";

/**
 * The address the reader is connected from, asked for by the BROWSER.
 *
 * The API reports `your_ip` as whoever made the request. These pages are
 * rendered by the panel's own server, so the value that came with the page was
 * the server's address — "Only my IP" allowed the server, the lock-out check
 * guarded the server, and Fail2ban offered to ignore the server. Asking the
 * same endpoint again from the browser gets the reader's public address.
 *
 * Null until that answer arrives (and if it fails): no address is offered
 * rather than the wrong one.
 */
const SOURCES = {
  firewall: { load: getFirewall, pick: (data) => data?.your_ip },
  fail2ban: { load: getFail2ban, pick: (data) => data?.fail2ban?.your_ip },
};

const BrowserIpContext = createContext(null);

export function BrowserIpProvider({ source, children }) {
  const [state, setState] = useState({ ip: null, settled: false });
  useEffect(() => {
    const controller = new AbortController();
    const { load, pick } = SOURCES[source];
    load({ signal: controller.signal })
      .then(({ data }) => setState({ ip: pick(data) ?? null, settled: true }))
      .catch(() => {
        if (!controller.signal.aborted) setState({ ip: null, settled: true });
      });
    return () => controller.abort();
  }, [source]);
  return <BrowserIpContext.Provider value={state}>{children}</BrowserIpContext.Provider>;
}

export function useBrowserIp() {
  return useContext(BrowserIpContext)?.ip ?? null;
}

/**
 * Whether the lookup has answered. An action that adds "your" address must
 * wait for it: "Set up protection" clicked in the second before it arrived
 * sent no address at all, and the API refused with its lock-out warning.
 */
export function useBrowserIpSettled() {
  return useContext(BrowserIpContext)?.settled ?? true;
}

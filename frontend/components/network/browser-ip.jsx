"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { getFirewall } from "@/lib/api/firewall";
import { getFail2ban } from "@/lib/api/fail2ban";

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

// Wait for the address, or the request sends none and the API refuses with a lockout warning.
export function useBrowserIpSettled() {
  return useContext(BrowserIpContext)?.settled ?? true;
}

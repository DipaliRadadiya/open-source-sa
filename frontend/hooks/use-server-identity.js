"use client";

import { useEffect, useState } from "react";
import { getServerIdentity } from "@/lib/api/server-metrics";

const KEY = "panel.server-identity";
const TTL_MS = 10 * 60 * 1000;

function cached() {
  try {
    const entry = JSON.parse(sessionStorage.getItem(KEY) ?? "null");
    return entry && Date.now() - entry.at < TTL_MS ? entry.value : null;
  } catch {
    return null;
  }
}

// /server/facts runs commands on the server, so ask once per tab every 10 minutes.
export function useServerIdentity() {
  const [identity, setIdentity] = useState(null);

  useEffect(() => {
    let live = true;
    const hit = cached();
    (hit ? Promise.resolve(hit) : getServerIdentity())
      .then((value) => {
        if (!live || !value?.hostname) return;
        setIdentity(value);
        if (hit) return;
        try {
          sessionStorage.setItem(KEY, JSON.stringify({ at: Date.now(), value }));
        } catch {
          // storage blocked; ignore
        }
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, []);

  return identity;
}

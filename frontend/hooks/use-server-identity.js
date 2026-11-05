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

// Which machine this is: the sidebar card and the breadcrumb's root. `/server/facts` runs
// commands on the server, so it is asked once per tab every ten minutes, not on every
// navigation. Null until there is an answer: a guessed name would be worse than none.
export function useServerIdentity() {
  const [identity, setIdentity] = useState(null);

  useEffect(() => {
    let live = true;
    const hit = cached();
    // One path for both: a cached answer resolves at once, a miss asks the server.
    (hit ? Promise.resolve(hit) : getServerIdentity())
      .then((value) => {
        if (!live || !value?.hostname) return;
        setIdentity(value);
        if (hit) return;
        try {
          sessionStorage.setItem(KEY, JSON.stringify({ at: Date.now(), value }));
        } catch {
          // Private mode: it is just asked again next time.
        }
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, []);

  return identity;
}

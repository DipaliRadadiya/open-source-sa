import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Server } from "lucide-react";
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

// Which machine this is, at the top of the server menu. `/server/facts` runs
// commands on the server, so it is asked once per tab every ten minutes, not
// on every navigation. Nothing renders until there is an answer: a guessed
// name or a hard-coded "Online" would be worse than no card.
export function SidebarServerCard() {
  const t = useTranslations("common");
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
          // Private mode: the card still shows, it is just asked again next time.
        }
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, []);

  if (!identity) return null;

  return (
    <Link
      href="/dashboard"
      prefetch={false}
      className="relative flex items-center gap-3 overflow-hidden rounded-xl bg-hero p-3 text-white shadow-e2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring group-data-[collapsible=icon]:hidden"
    >
      <span aria-hidden className="bg-hero-grid pointer-events-none absolute inset-0" />
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/25">
        <Server className="size-4" aria-hidden />
      </span>
      <span className="relative min-w-0 flex-1">
        <span className="block truncate font-mono text-sm font-semibold" title={identity.hostname}>
          {identity.hostname}
        </span>
        <span className="flex min-w-0 items-center gap-1.5 text-xs text-white/85">
          <span aria-hidden className="size-1.5 shrink-0 rounded-full bg-emerald-300" />
          <span className="truncate">
            {t("serverOnline")}
            {identity.ip ? ` · ${identity.ip}` : ""}
          </span>
        </span>
      </span>
    </Link>
  );
}

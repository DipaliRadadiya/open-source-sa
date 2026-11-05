import { HoverHint } from "@/components/ui/hover-hint";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Server } from "lucide-react";
import { CopyButton } from "@/components/ui/copy-button";
import { useServerIdentity } from "@/hooks/use-server-identity";

// Which machine this is, at the top of the server menu. `/server/facts` runs
// commands on the server, so it is asked once per tab every ten minutes, not
// on every navigation. Nothing renders until there is an answer: a guessed
// name or a hard-coded "Online" would be worse than no card.
export function SidebarServerCard() {
  const t = useTranslations("common");
  const td = useTranslations("serverDashboard");
  const identity = useServerIdentity();

  if (!identity) return null;

  return (
    // A plate with one link stretched over it and the IP's copy button above that link: a button
    // inside an <a> is invalid and would also navigate.
    <div className="relative flex items-center gap-3 overflow-hidden rounded-[2px] border border-primary/70 bg-card p-3 transition-colors has-[a:hover]:border-primary group-data-[collapsible=icon]:hidden">
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-[2px] border border-primary/60 text-[var(--primary-700,var(--primary))] dark:text-primary">
        <Server className="size-4" aria-hidden />
      </span>
      <span className="relative min-w-0 flex-1">
        {/* One line, cut at the end (Krishna, 5 Nov): the breadcrumb carries the whole name, and
            hovering the card shows it. */}
        <span className="block truncate font-mono text-sm font-semibold">{identity.hostname}</span>
        <span className="flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
          <span aria-hidden className="size-1.5 shrink-0 bg-primary" />
          {t("serverOnline")}
        </span>
        {identity.ip ? (
          // Its own line, no leading dot, with a copy button (Krishna, 5 Nov).
          <span className="mt-0.5 flex items-center gap-1 font-mono text-xs text-muted-foreground">
            <span className="min-w-0 truncate">{identity.ip}</span>
            <CopyButton value={identity.ip} label={td("info.copyIp")} className="relative z-20 size-5 [&_svg]:size-3" />
          </span>
        ) : null}
      </span>
      <HoverHint content={identity.hostname} side="right">
        <Link
          href="/dashboard"
          prefetch={false}
          aria-label={identity.hostname}
          className="absolute inset-0 z-10 rounded-[2px] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
        />
      </HoverHint>
    </div>
  );
}

import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { AppWindow, ChevronRight, Clock, Database, ShieldAlert, ShieldCheck, ShieldOff, ShieldQuestion, Users } from "lucide-react";
import { cn } from "@/lib/utils";
import { FirewallQuickSwitch } from "@/components/dashboard/firewall-quick-switch";
import { HoverHint } from "@/components/ui/hover-hint";

// Same hairline cells as the "Right now" strip, so the two read as one family.
const CELL = "min-w-0 bg-card shadow-[inset_-1px_0_0_var(--border),inset_0_-1px_0_var(--border)]";

const FIREWALL = {
  on: { icon: ShieldCheck, tone: "success", value: "text-success" },
  off: { icon: ShieldOff, tone: "destructive", value: "text-destructive" },
  exposed: { icon: ShieldAlert, tone: "warning", value: "text-warning" },
  unknown: { icon: ShieldQuestion, tone: "neutral", value: "text-muted-foreground" },
};

// Steel tiles (Krishna, 5 Nov: the one-line version looked plain): the icon in a square edged
// in its tone, the label above the number, an arrow where the tile opens its page. The firewall
// tile ends in its switch instead, centred on the same line as the icon.
const TONES = {
  info: "border-primary/45 bg-primary/10 text-[var(--primary-700,var(--primary))] dark:text-primary",
  success: "border-success/45 bg-success/10 text-success",
  warning: "border-warning/45 bg-warning/10 text-warning",
  destructive: "border-destructive/45 bg-destructive/10 text-destructive",
  neutral: "border-border bg-muted/40 text-muted-foreground",
};

function Tile({ href, icon: Icon, label, value, sub, hint, tone = "info", valueTone, control, className }) {
  const body = (
    <>
      <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-[2px] border", TONES[tone])}>
        <Icon className="size-4" aria-hidden />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[13px] font-medium text-muted-foreground">{label}</span>
        <span className="mt-0.5 flex flex-wrap items-baseline gap-x-2">
          <span className={cn("text-xl leading-tight font-semibold tabular-nums", valueTone)}>{value}</span>
          {sub ? <span className="text-xs text-muted-foreground">{sub}</span> : null}
        </span>
      </span>
    </>
  );
  return (
    <li className={cn(CELL, "flex items-center gap-3 px-4 py-3", className)}>
      {href ? (
        <HoverHint content={hint}><Link href={href} prefetch={false} className="group -m-1 flex min-w-0 flex-1 items-center gap-3 rounded-sm p-1 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring">
          {body}
          {control ? null : (
            <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" aria-hidden />
          )}
        </Link></HoverHint>
      ) : (
        <div className="flex min-w-0 flex-1 items-center gap-3">{body}</div>
      )}
      {/* Beside the link, not inside it: a switch inside a link would also navigate. */}
      {control ? <span className="flex shrink-0 items-center">{control}</span> : null}
    </li>
  );
}

/**
 * What is on this server, and whether its firewall is on (user feedback, 5 Nov: the old
 * dashboard's counts were missed, and a server with its firewall off looked "all fine").
 * Only parts the user may open; an unread count is "—", never 0.
 */
export async function DashboardOverview({ applications, overview, canManageFirewall = false }) {
  const t = await getTranslations("serverDashboard.overview");
  const ta = await getTranslations("applications");
  const td = await getTranslations("databases");
  const tc = await getTranslations("cronJobs");
  const tu = await getTranslations("systemUsers");
  const tf = await getTranslations("firewall");
  const shown = (value) => (value === null || value === undefined ? "—" : value);

  const tiles = [];
  if (applications !== undefined) {
    // How many are online is the status bar's line just above; here only the count.
    tiles.push({
      key: "applications",
      href: "/applications",
      icon: AppWindow,
      label: ta("title"),
      value: shown(applications?.length),
    });
  }
  if (overview.databases !== undefined) tiles.push({ key: "databases", href: "/databases", icon: Database, label: td("title"), value: shown(overview.databases) });
  if (overview.cronjobs !== undefined) tiles.push({ key: "cronjobs", href: "/cron-jobs", icon: Clock, label: tc("title"), value: shown(overview.cronjobs) });
  if (overview.systemUsers !== undefined) tiles.push({ key: "systemUsers", href: "/system-users", icon: Users, label: tu("title"), value: shown(overview.systemUsers) });
  if (overview.firewall) {
    const { state, rules } = overview.firewall;
    const look = FIREWALL[state] ?? FIREWALL.unknown;
    tiles.push({
      key: "firewall",
      href: "/firewall",
      icon: look.icon,
      tone: look.tone,
      valueTone: look.value,
      label: tf("title"),
      value: t(`firewall.${state}`),
      // Only the rule count, and only when on (Krishna, 5 Nov: "Off" with a sentence under it looked
      // messy). The red Off and its icon say enough; the hint is the tile's tooltip.
      sub: state === "on" && rules !== null ? t("firewallRules", { count: rules }) : null,
      hint: t(`firewall.${state}Hint`),
      // Two cells where the grid has room to spare (2 and 3 columns), so no row ends half empty.
      className: "col-span-2 lg:col-span-1",
      control: <FirewallQuickSwitch enabled={overview.firewall.enabled} canManage={canManageFirewall} label={tf("title")} />,
    });
  }

  if (!tiles.length) return null;

  return (
    <section aria-label={t("label")} className="overflow-hidden rounded-xl border bg-card">
      {/* -mr/-mb hide the outermost cells' edges under the card's clipped border. */}
      <ul className="-mr-px -mb-px grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
        {tiles.map(({ key, ...tile }) => (
          <Tile key={key} {...tile} />
        ))}
      </ul>
    </section>
  );
}

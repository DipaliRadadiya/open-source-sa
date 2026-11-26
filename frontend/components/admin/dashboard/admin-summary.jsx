import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, CircleAlert, ShieldAlert, ShieldCheck, UserRoundCog, Users } from "lucide-react";
import { cn } from "@/lib/utils";
import { FACT_LABEL } from "@/lib/theme/facts";

const TONE = {
  ok: "bg-success-soft text-success",
  warn: "bg-warning-soft text-warning",
  quiet: "bg-muted text-muted-foreground",
};

function Tile({ href, icon: Icon, tone, label, value, note, noteTone }) {
  return (
    <Link
      href={href}
      prefetch={false}
      className="group flex min-w-0 items-start gap-3 rounded-2xl bg-card p-4 shadow-e1 ring-1 ring-border/70 transition-colors hover:ring-primary/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <span className={cn("flex size-8 shrink-0 items-center justify-center rounded-xl", TONE[tone])}>
        <Icon className="size-4" aria-hidden />
      </span>
      <span className="min-w-0 flex-1 space-y-1">
        <span className={cn(FACT_LABEL, "block")}>{label}</span>
        <span className="block text-sm font-semibold break-words">{value}</span>
        {note ? (
          <span className={cn("block text-xs break-words", noteTone === "warn" ? "font-medium text-warning" : "text-muted-foreground")}>{note}</span>
        ) : null}
      </span>
      <ArrowRight className="mt-1 size-3.5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden />
    </Link>
  );
}

// Three one-line answers, each opening the page with the detail (the
// dashboard shows what matters, not every person and number).
export async function AdminSummary({ signIns = null, users = null, never = null, impersonation = null }) {
  const [t, tp, ta, format] = await Promise.all([
    getTranslations("admin.signIns"),
    getTranslations("admin.people"),
    getTranslations("admin.attention"),
    getFormatter(),
  ]);

  // A failed read never says "none".
  const signInTile = !signIns
    ? { tone: "quiet", icon: CircleAlert, value: t("failed") }
    : signIns.week === 0
      ? { tone: "ok", icon: ShieldCheck, value: t("none") }
      : {
          tone: "warn",
          icon: ShieldAlert,
          value: t("failedCount", { shown: signIns.capped ? `${format.number(signIns.week)}+` : format.number(signIns.week) }),
          note: signIns.address ? ta("signInsFrom", { address: signIns.address.value }) : null,
        };

  const known = (n) => n !== undefined && n !== null;
  const peopleValue =
    known(users?.total) && known(users?.admins)
      ? `${tp("usersCount", { count: users.total })} · ${tp("adminsCount", { count: users.admins })}`
      : "—";

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      <Tile href="/admin/activity-log?security=1" label={t("title")} {...signInTile} />
      <Tile
        href="/admin/users"
        icon={Users}
        tone={never ? "warn" : "quiet"}
        label={tp("users")}
        value={peopleValue}
        note={never ? tp("neverCount", { count: never }) : null}
        noteTone="warn"
      />
      <Tile
        href="/admin/activity-log?action=impersonation_started"
        icon={UserRoundCog}
        tone="quiet"
        label={tp("impersonation")}
        value={
          impersonation?.failed
            ? "—"
            : impersonation?.total
              ? tp("impersonationUsed", { count: impersonation.total, when: impersonation.last?.created_at_human ?? "" })
              : tp("impersonationNever")
        }
      />
    </div>
  );
}

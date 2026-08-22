import Link from "next/link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, ShieldCheck, UserRoundCog, Users } from "lucide-react";
import { Card } from "@/components/ui/card";

/**
 * Who can get in, and with what.
 *
 * Demoted from two of the four hero tiles: the raw number of users is not a
 * thing that ever needs action. How many of them are ADMINISTRATORS is — it is
 * the one figure here with a security answer attached — so it leads, and the
 * total is its context rather than the other way round.
 */
export async function PeopleCard({ users, roles, impersonation }) {
  const [t, format] = await Promise.all([getTranslations("admin.people"), getFormatter()]);
  const num = (n) => format.number(n ?? 0);

  // Signing in as another user is the single most powerful thing anyone can do
  // here, and until now it was one line among eight hundred in the log. It sits
  // with the other access facts because that is the question it answers: who
  // has been able to act as whom.
  const impersonationRow = impersonation?.failed
    ? null
    : {
        key: "impersonation",
        icon: UserRoundCog,
        href: "/admin/activity-log?action=impersonation_started",
        label: t("impersonation"),
        value: num(impersonation?.total ?? 0),
        hint: impersonation?.total
          ? t("impersonationLast", { when: impersonation.last?.created_at_human ?? "" })
          : t("impersonationNever"),
      };

  const rows = [
    {
      key: "admins",
      icon: Users,
      href: "/admin/users",
      label: t("admins"),
      value: num(users?.admins),
      hint: t("ofUsers", { total: num(users?.total) }),
    },
    {
      key: "roles",
      icon: ShieldCheck,
      href: "/admin/roles",
      label: t("roles"),
      value: num(roles?.total),
      hint: t("rolesHint"),
    },
    impersonationRow,
  ].filter(Boolean);

  return (
    <Card className="flex flex-col gap-0 overflow-hidden py-0 shadow-sm">
      <div className="border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
      </div>

      <ul className="divide-y">
        {rows.map((row) => (
          <li key={row.key}>
            <Link
              href={row.href}
              className="group flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <row.icon className="size-3.5" aria-hidden />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{row.label}</p>
                <p className="text-xs text-muted-foreground">{row.hint}</p>
              </div>
              <p className="shrink-0 font-mono text-base leading-none font-semibold tabular-nums">
                {row.value}
              </p>
            </Link>
          </li>
        ))}
      </ul>

      <div className="mt-auto border-t bg-muted/20 px-5 py-2.5">
        <Link
          href="/admin/users"
          className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
          {t("manage")}
          <ArrowRight className="size-3.5" aria-hidden />
        </Link>
      </div>
    </Card>
  );
}

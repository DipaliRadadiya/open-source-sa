import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, ShieldCheck, UserRoundCog, Users } from "lucide-react";
import { Card } from "@/components/ui/card";

// The administrator count leads: it is the security-relevant figure.
export async function PeopleCard({ users, roles, impersonation }) {
  const [t, format] = await Promise.all([getTranslations("admin.people"), getFormatter()]);
  const num = (n) => format.number(n ?? 0);

  // Impersonation is the most powerful action available, so it is surfaced here.
  const impersonationRow = impersonation?.failed
    ? null
    : {
        key: "impersonation",
        icon: UserRoundCog,
        href: "/admin/activity-log?action=impersonation_started",
        label: t("impersonation"),
        value: impersonation?.total
          ? t("impersonationUsed", {
              count: impersonation.total,
              when: impersonation.last?.created_at_human ?? "",
            })
          : t("impersonationNever"),
      };

  // A dash, never a zero, when the stats read failed (including a 403).
  const rows = [
    {
      key: "admins",
      icon: Users,
      href: "/admin/users",
      label: t("admins"),
      value: users
        ? t("adminsOf", { admins: num(users.admins), total: num(users.total) })
        : "—",
    },
    {
      key: "roles",
      icon: ShieldCheck,
      href: "/admin/roles",
      label: t("roles"),
      value: roles ? t("rolesConfigured", { count: roles.total ?? 0 }) : "—",
    },
    impersonationRow,
  ].filter(Boolean);

  return (
    <Card className="flex flex-col gap-0 overflow-hidden py-0">
      <div className="border-b px-5 py-3.5">
        <h2 className="text-[15px] leading-snug font-semibold tracking-tight">
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
                <p className="text-xs text-muted-foreground">{row.value}</p>
              </div>
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

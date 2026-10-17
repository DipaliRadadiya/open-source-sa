import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, UserRoundCog } from "lucide-react";
import { Card } from "@/components/ui/card";
import { FACT_LABEL } from "@/lib/theme/facts";
import { roleName } from "@/lib/roles/role-label";
import { roleCounts } from "@/lib/admin/dashboard-facts";

// Who can do what: three counts, then how many people hold each role (sign-ins were
// not what an administrator looks for here, Krishna 17 Oct).
const ROLES_SHOWN = 5;

export async function PeopleCard({ users, roles, impersonation, userList = null }) {
  const [t, tr, format] = await Promise.all([getTranslations("admin.people"), getTranslations("roles"), getFormatter()]);
  // A dash, never a zero, when the stats read failed (including a 403).
  const num = (n) => (n === undefined || n === null ? "—" : format.number(n));

  const stats = [
    { key: "users", label: t("users"), value: num(users?.total), href: "/admin/users" },
    { key: "admins", label: t("admins"), value: num(users?.admins), href: "/admin/users?is_admin=1" },
    { key: "roles", label: t("roles"), value: num(roles?.total), href: "/admin/roles" },
  ];

  const inUse = roleCounts(userList ?? []).slice(0, ROLES_SHOWN);

  return (
    <Card className="flex h-full flex-col gap-0 overflow-hidden py-0">
      <div className="border-b px-5 py-3.5">
        <h2 className="text-[15px] leading-snug font-semibold tracking-tight">{t("title")}</h2>
      </div>

      {/* Rows, not three columns: in Russian the column labels ran into each other. */}
      <ul className="divide-y border-b">
        {stats.map((stat) => (
          <li key={stat.key}>
            <Link
              href={stat.href}
              prefetch={false}
              className="flex items-baseline justify-between gap-3 px-5 py-2.5 text-sm transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            >
              <span className="min-w-0 font-medium">{stat.label}</span>
              <span className="shrink-0 text-base font-semibold tabular-nums">{stat.value}</span>
            </Link>
          </li>
        ))}
      </ul>

      <div className="px-5 pt-3 pb-1">
        <p className={FACT_LABEL}>{t("rolesInUse")}</p>
      </div>
      {userList === null ? (
        <p className="px-5 pb-3 text-sm text-muted-foreground">{t("rolesFailed")}</p>
      ) : inUse.length ? (
        <ul className="pb-1">
          {inUse.map(({ role, count }) => (
            <li key={role.id}>
              <Link
                href={`/admin/roles/${role.id}`}
                prefetch={false}
                className="flex items-baseline justify-between gap-3 px-5 py-1.5 text-sm transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
              >
                <span className="min-w-0 truncate font-medium">{roleName(role, tr)}</span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{t("roleMembers", { count })}</span>
              </Link>
            </li>
          ))}
        </ul>
      ) : (
        <p className="px-5 pb-3 text-sm text-muted-foreground">{t("rolesNone")}</p>
      )}

      {/* Impersonation is the most powerful action available, so it stays in view. */}
      {impersonation?.failed ? null : (
        <Link
          href="/admin/activity-log?action=impersonation_started"
          prefetch={false}
          className="mx-5 mt-2 mb-4 flex items-center gap-3 rounded-lg border px-3 py-2 text-sm transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
        >
          <UserRoundCog className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          <span className="font-medium">{t("impersonation")}</span>
          <span className="ml-auto text-xs text-muted-foreground">
            {impersonation?.total
              ? t("impersonationUsed", { count: impersonation.total, when: impersonation.last?.created_at_human ?? "" })
              : t("impersonationNever")}
          </span>
        </Link>
      )}

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

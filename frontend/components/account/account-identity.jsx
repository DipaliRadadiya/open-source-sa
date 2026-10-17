import { useFormatter, useTranslations } from "next-intl";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { roleName } from "@/lib/roles/role-label";
import { initials } from "@/lib/format/initials";
import { parseApiDate } from "@/lib/format/api-date";

// Who this page is about, above the tabs: the same avatar as the user menu, the
// roles that decide what you can open, and when and from where you last signed in.
export function AccountIdentity({ user }) {
  const t = useTranslations("account.identity");
  const tr = useTranslations("roles");
  const format = useFormatter();
  const roles = user?.roles ?? [];
  // A date, not "1 week ago": "member since" names a day.
  const created = parseApiDate(user?.created_at);

  return (
    <Card className="flex-row flex-wrap items-center gap-x-6 gap-y-4 px-5 py-4">
      <div className="flex min-w-0 flex-1 basis-72 items-center gap-4">
        <Avatar className="size-12">
          <AvatarFallback className="bg-linear-to-br from-[var(--primary-600,var(--primary))] to-[color-mix(in_oklch,var(--primary-600,var(--primary)),#6366f1_45%)] text-base font-semibold text-white">
            {initials(user?.name)}
          </AvatarFallback>
        </Avatar>
        <div className="min-w-0 space-y-1">
          <p className="truncate text-lg font-semibold tracking-tight">{user?.name}</p>
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="mr-1 truncate font-mono text-sm text-muted-foreground">@{user?.username}</span>
            {roles.map((role) => (
              <Badge key={role.id} variant="outline" className="font-normal">
                {roleName(role, tr)}
              </Badge>
            ))}
          </div>
        </div>
      </div>

      <dl className="grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
        <div>
          <dt className="text-xs text-muted-foreground">{t("lastSignIn")}</dt>
          <dd className="font-medium whitespace-nowrap">{user?.last_login_at_human ?? t("never")}</dd>
          {user?.last_login_ip ? (
            <dd className="font-mono text-xs text-muted-foreground">{user.last_login_ip}</dd>
          ) : null}
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">{t("memberSince")}</dt>
          <dd className="font-medium">{created ? format.dateTime(created, { dateStyle: "medium" }) : "—"}</dd>
        </div>
      </dl>
    </Card>
  );
}

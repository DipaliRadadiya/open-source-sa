import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getMyActivity } from "@/lib/account/get-my-activity";
import { ChangePasswordForm } from "@/components/account/change-password-form";
import { AccountActivity } from "@/components/account/account-activity";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from "@/components/ui/card";

export const dynamic = "force-dynamic";

export default async function AccountPage({ searchParams }) {
  const sp = await searchParams;
  const [user, { activity_log: entries, meta }, t] = await Promise.all([
    getCurrentUser(),
    getMyActivity(sp),
    getTranslations("account"),
  ]);

  const isAdmin = user?.is_admin;
  const roles = user?.roles ?? [];

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t("profile.title")}</CardTitle>
          <CardDescription>{t("profile.description")}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 text-sm sm:grid-cols-3">
          <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">{t("profile.name")}</span>
            <span className="font-medium">{user?.name}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">{t("profile.username")}</span>
            <span className="font-medium">@{user?.username}</span>
          </div>
          <div className="flex flex-col items-start gap-1">
            <span className="text-muted-foreground">{t("profile.accountType")}</span>
            <Badge variant={isAdmin ? "default" : "secondary"}>
              {isAdmin ? t("profile.roleAdmin") : t("profile.roleUser")}
            </Badge>
          </div>
          <div className="flex flex-col items-start gap-1 sm:col-span-3">
            <span className="text-muted-foreground">{t("profile.roles")}</span>
            <div className="flex flex-wrap gap-1">
              {roles.length ? (
                roles.map((r) => (
                  <Badge key={r.id} variant="secondary">
                    {r.name}
                  </Badge>
                ))
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("password.title")}</CardTitle>
          <CardDescription>{t("password.description")}</CardDescription>
        </CardHeader>
        <CardContent>
          <ChangePasswordForm />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("activity.title")}</CardTitle>
          <CardDescription>{t("activity.description")}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <AccountActivity data={entries} />
          <DataTablePagination meta={meta} />
        </CardContent>
      </Card>
    </div>
  );
}

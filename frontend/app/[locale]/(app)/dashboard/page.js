import { getTranslations } from "next-intl/server";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from "@/components/ui/card";

export const dynamic = "force-dynamic";

// Server-management (user-facing) dashboard. Deliberately free of admin-level
// data (users/roles/activity stats) — that lives in the separate /admin panel.
export default async function DashboardPage() {
  const [user, t] = await Promise.all([
    getCurrentUser(),
    getTranslations("dashboard"),
  ]);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">
          {t("welcome", { name: user?.name ?? "" })}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t("account")}</CardTitle>
          <CardDescription>{t("accountDescription")}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">{t("name")}</span>
            <span className="font-medium">{user?.name}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">{t("username")}</span>
            <span className="font-medium">@{user?.username}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-muted-foreground">{t("accountType")}</span>
            <span className="font-medium">
              {user?.is_admin ? t("admin") : t("user")}
            </span>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

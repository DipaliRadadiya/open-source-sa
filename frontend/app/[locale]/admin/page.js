import { getFormatter, getTranslations } from "next-intl/server";
import { Users, ShieldCheck, Activity, History } from "lucide-react";
import { getDashboardStats } from "@/lib/dashboard/get-dashboard";
import { StatCard } from "@/components/sections/stat-card";

export const dynamic = "force-dynamic";

export default async function AdminDashboardPage() {
  const [t, format, stats] = await Promise.all([
    getTranslations("admin"),
    getFormatter(),
    getDashboardStats(),
  ]);

  const num = (n) => format.number(n ?? 0);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t("dashboardTitle")}
        </h1>
        <p className="text-sm text-muted-foreground">{t("dashboardSubtitle")}</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          icon={Users}
          title={t("totalUsers")}
          value={num(stats?.users.total)}
          hint={
            stats
              ? t("usersBreakdown", {
                  admins: num(stats.users.admins),
                  nonAdmins: num(stats.users.non_admins),
                })
              : undefined
          }
        />
        <StatCard
          icon={ShieldCheck}
          title={t("roles")}
          value={num(stats?.roles.total)}
          hint={t("rolesHint")}
        />
        <StatCard
          icon={Activity}
          title={t("activityToday")}
          value={num(stats?.activity.today)}
          hint={t("activityTodayHint")}
        />
        <StatCard
          icon={History}
          title={t("activityTotal")}
          value={num(stats?.activity.total)}
          hint={t("activityTotalHint")}
        />
      </div>
    </div>
  );
}

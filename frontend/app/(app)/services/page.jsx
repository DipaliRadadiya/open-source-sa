import { redirect } from "next/navigation";
import { getTranslations, getFormatter } from "next-intl/server";
import { Cog } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getServices } from "@/lib/services/get-services";
import { getPhp } from "@/lib/php/get-php";
import { ServicesPanel } from "@/components/services/services-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("services");
  return { title: t("title") };
}

export default async function ServicesPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("services"),
  ]);

  if (!can(permissions, "service", "view")) redirect("/dashboard");

  const canManage = can(permissions, "service", "manage");

  // PHP moved to its own feature behind its own permission. The link from an
  // FPM row is only offered to someone who can actually open that page —
  // otherwise it lands on a redirect back to the dashboard.
  const canSeePhp = can(permissions, "php", "view");
  const [{ services, failed }, php] = await Promise.all([
    getServices(),
    canSeePhp ? getPhp() : Promise.resolve({ data: null }),
  ]);
  const phpVersions = php.data?.versions ?? [];

  // Formatted server-side against the configured display timezone, so it can't
  // hydrate to a different clock than the one the rest of the panel quotes.
  const format = await getFormatter();
  const checkedAt = format.dateTime(new Date(), { timeStyle: "short" });

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : services.length === 0 ? (
        <EmptyState
          icon={Cog}
          title={t("empty.title")}
          description={t("empty.body")}
        />
      ) : (
        <NavTransitionProvider>
          <ServicesPanel
            initialServices={services}
            initialCheckedAt={checkedAt}
            phpVersions={phpVersions}
            canManage={canManage}
          />
        </NavTransitionProvider>
      )}
    </div>
  );
}

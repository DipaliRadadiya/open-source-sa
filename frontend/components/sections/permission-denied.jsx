import { Ban, ShieldOff } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { getServerCapabilities } from "@/lib/applications/get-applications";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { unavailableOnServer } from "@/lib/permissions/stack-feature";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState } from "@/components/data-table/empty-state";

export async function PermissionDenied({ title, description, feature, typeFeature, notForType = false }) {
  const t = await getTranslations("common.permissionDenied");
  if (notForType || (typeFeature && (await getCurrentUser().catch(() => null))?.is_admin)) {
    return (
      <div className="space-y-6">
        <PageHeader title={title} />
        <EmptyState icon={Ban} title={t("notForTypeTitle")} description={t("notForTypeDescription")} />
      </div>
    );
  }
  const profiles = feature ? (await getServerCapabilities().catch(() => null))?.servingProfiles : null;
  const unavailable = feature ? unavailableOnServer(feature, profiles) : false;
  const reason =
    feature === "database" && Array.isArray(profiles) && profiles.includes("docker")
      ? t("unavailableDatabaseDocker")
      : t("unavailableDescription");

  if (unavailable) {
    return (
      <div className="space-y-6">
        <PageHeader title={title} />
        <EmptyState
          icon={Ban}
          title={t("unavailableTitle")}
          description={reason}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader title={title} />
      <EmptyState
        icon={ShieldOff}
        title={t("title", { feature: title })}
        description={description ?? t("description")}
      />
    </div>
  );
}

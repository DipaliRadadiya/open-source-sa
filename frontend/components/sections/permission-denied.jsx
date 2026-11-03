import { Ban, ShieldOff } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { getServerCapabilities } from "@/lib/applications/get-applications";
import { unavailableOnServer } from "@/lib/permissions/stack-feature";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState } from "@/components/data-table/empty-state";

// Refuses in place instead of redirecting, so the URL stays on the right screen.
// `feature`: the permission name, for screens that exist only on some stacks. When the
// server does not run that feature, nobody can be granted it, so "ask an administrator"
// would send the administrator in a circle.
export async function PermissionDenied({ title, description, feature }) {
  const t = await getTranslations("common.permissionDenied");
  const profiles = feature ? (await getServerCapabilities().catch(() => null))?.servingProfiles : null;
  const unavailable = feature ? unavailableOnServer(feature, profiles) : false;
  // A Docker server has no database screen on purpose; users asked whether it was (3 Nov).
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

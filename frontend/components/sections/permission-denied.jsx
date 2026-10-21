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
  const unavailable = feature
    ? unavailableOnServer(feature, (await getServerCapabilities().catch(() => null))?.servingProfiles)
    : false;

  if (unavailable) {
    return (
      <div className="space-y-6">
        <PageHeader title={title} />
        <EmptyState
          icon={Ban}
          title={t("unavailableTitle")}
          description={t("unavailableDescription")}
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

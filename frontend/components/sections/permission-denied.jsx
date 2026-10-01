import { ShieldOff } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState } from "@/components/data-table/empty-state";

// Refuses in place instead of redirecting, so the URL stays on the right screen.
export async function PermissionDenied({ title, description }) {
  const t = await getTranslations("common.permissionDenied");

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

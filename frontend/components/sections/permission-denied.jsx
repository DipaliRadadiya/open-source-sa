import { ShieldOff } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState } from "@/components/data-table/empty-state";

/**
 * "You do not have access to X", on the screen X, named as X.
 *
 * Refusing in place instead of redirecting: a role that cannot open the dashboard
 * either would otherwise be refused for the wrong page. It also keeps the URL, so
 * back, the address bar and shared links refer to the right screen.
 *
 * `title` is the page's own heading, so the refusal never gives the screen a
 * second name.
 */
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

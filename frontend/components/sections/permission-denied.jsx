import { ShieldOff } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState } from "@/components/data-table/empty-state";

/**
 * "You do not have access to X" — on the screen X, named as X.
 *
 * Every permission-gated page used to `redirect("/dashboard")`. That is fine
 * for an administrator, who lands somewhere they can read. For a role that
 * cannot open the dashboard either, asking for /php produced a bounce to a
 * second wall which then explained the WRONG wall: the reader asked about PHP
 * and the panel answered "you don't have access to the dashboard".
 *
 * Refusing in place also keeps the URL the reader typed, so the address bar,
 * the back button and a link pasted into a support thread all still refer to
 * the thing being discussed.
 *
 * `title` is the page's own heading, passed in by the page, so the refusal
 * never carries a second name for a screen the sidebar already labels.
 */
export async function PermissionDenied({ title }) {
  const t = await getTranslations("common.permissionDenied");

  return (
    <div className="space-y-6">
      <PageHeader title={title} />
      <EmptyState
        icon={ShieldOff}
        title={t("title", { feature: title })}
        description={t("description")}
      />
    </div>
  );
}

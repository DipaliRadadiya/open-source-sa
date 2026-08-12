import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getGitAccounts, getGitProviders } from "@/lib/git/get-git";
import { AccountsCard } from "@/components/integrations/git/accounts-card";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("git");
  return { title: t("title") };
}

export default async function GitIntegrationsPage() {
  const [permissions, t, list, providerList] = await Promise.all([
    getPermissions(),
    getTranslations("git"),
    getGitAccounts(),
    // Fetched alongside rather than on demand: the connect dialog opens
    // instantly, and a failure here disables the button with a reason instead
    // of opening an empty form.
    getGitProviders(),
  ]);

  if (!can(permissions, "git", "view")) redirect("/dashboard");
  const canManage = can(permissions, "git", "manage");

  if (list.failed) return <LoadFailed description={t("loadFailed")} status={list.status} failure={list.failure} />;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      <div className="max-w-4xl">
        <AccountsCard
          accounts={list.accounts}
          providers={providerList.providers}
          providersFailed={providerList.failed}
          canManage={canManage}
        />
      </div>
    </div>
  );
}

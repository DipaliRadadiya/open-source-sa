import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { cookies } from "next/headers";
import { getActiveRestore } from "@/lib/backups/get-backups";
import { DISMISSED_RESTORES_COOKIE, parseDismissedRestores } from "@/lib/backups/dismissed-restores";
import { BackupsTabs } from "@/components/backups/backups-tabs";
import { RestoreWatch } from "@/components/backups/restore-watch";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("backups");
  return { title: t("title") };
}

export default async function BackupsLayout({ children }) {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("backups"),
  ]);

  if (!can(permissions, "backup", "view")) return <PermissionDenied title={t("title")} />;
  // Seeded from the server so it survives a reload and shows in other browsers. Same
  // rule as the application page: a finished restore stays (with its Undo) until it
  // is dismissed, instead of vanishing on the next refresh.
  const active = await getActiveRestore(undefined, {
    dismissed: parseDismissedRestores((await cookies()).get(DISMISSED_RESTORES_COOKIE)?.value),
  });

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* Lets a restore started on any tab raise the banner immediately. */}
      <RestoreWatch initial={active}>
        <BackupsTabs />
        {children}
      </RestoreWatch>
    </div>
  );
}

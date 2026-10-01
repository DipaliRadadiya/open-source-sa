import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getRestores } from "@/lib/backups/get-backups";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
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
  // An in-flight restore is shown on every tab; seeded from the server so it
  // survives a reload and shows in other browsers.
  const { restores } = await getRestores({ per_page: 5 });
  const active = restores.find((restore) => RESTORE_IN_FLIGHT.includes(restore.status)) ?? null;

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

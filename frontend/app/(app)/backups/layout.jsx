import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getRestores } from "@/lib/backups/get-backups";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { BackupsTabs } from "@/components/backups/backups-tabs";
import { RestoreWatch } from "@/components/backups/restore-watch";

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

  if (!can(permissions, "backup", "view")) redirect("/dashboard");

  // A restore in flight outranks whichever tab you are looking at: it is
  // rewriting a live site right now, and finding out by wandering onto the
  // right tab is not good enough. Seeded from the server so a reload — or
  // someone else's browser — still shows it.
  const { restores } = await getRestores({ per_page: 5 });
  const active = restores.find((restore) => RESTORE_IN_FLIGHT.includes(restore.status)) ?? null;

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* Wraps the tabs and the pages so a restore started on any of them can
          raise the banner here without waiting for the server to notice. */}
      <RestoreWatch initial={active}>
        <BackupsTabs />
        {children}
      </RestoreWatch>
    </div>
  );
}

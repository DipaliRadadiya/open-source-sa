import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { AppWindow } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { BackupsPanel } from "@/components/applications/backups/backups-panel";

// A database on its own gets the application backup screen, worded for a database. One
// that belongs to an application is backed up with it, and its own schedule is refused.
export function DatabaseBackups({ database, read, backups, destinations, backupOptions, archived = [], activeRestore = null, canManage, canHandleArchives }) {
  const t = useTranslations("backups.database");

  if (read.failed) {
    return <LoadFailed description={t("loadFailed")} status={read.status} failure={read.failure} message={read.message} debug={read.debug} />;
  }

  if (read.backedUpWith) {
    const owner = read.backedUpWith;
    return (
      <Card className="gap-0 py-0">
        <CardContent className="flex flex-wrap items-center gap-3 px-5 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <AppWindow className="size-5" aria-hidden />
          </span>
          <div className="min-w-60 flex-1 space-y-1">
            <p className="font-semibold">{t("backedUpWith.title", { application: owner.application_name ?? "" })}</p>
            <p className="text-sm text-muted-foreground">{t("backedUpWith.body")}</p>
          </div>
          <Button asChild variant="outline">
            <Link href={`/applications/${owner.application_id}/backups`}>{t("backedUpWith.action")}</Link>
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <BackupsPanel
      subject="database"
      database={{ id: database.id, name: database.name }}
      target={read.target}
      destinations={destinations}
      backups={backups.backups}
      total={backups.meta.total}
      backupsFailed={backups.failed}
      backupsForbidden={backups.failed && backups.status === 403}
      keptCount={
        backups.failed || backups.meta.total > backups.backups.length
          ? null
          : backups.backups.filter((backup) => backup.status === "verified" && !backup.is_safety).length
      }
      activeRestore={activeRestore}
      canManage={canManage}
      canRestore={canManage}
      canTurnOff={canManage}
      canHandleArchives={canHandleArchives}
      archived={archived}
      backupOptions={backupOptions}
    />
  );
}

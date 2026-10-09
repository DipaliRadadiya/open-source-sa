import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { DatabaseBackup, History, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { restoreStagingSafetyCopy } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { useRefresh } from "@/hooks/use-refresh";

// The undo for a database push: each push saves the live database first. Restoring one
// saves the current database too, so a restore is itself undoable.
export function SafetyCopiesCard({ appId, copies }) {
  const t = useTranslations("applications.staging.safetyCopies");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [target, setTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const when = (copy) => (copy.created_at ? format.relativeTime(new Date(copy.created_at)) : "—");

  async function restore() {
    if (!target) return;
    setPending(true);
    setError(null);
    try {
      await restoreStagingSafetyCopy(appId, target.name);
      await refreshAndWait();
      toast.success(t("restored", { when: when(target) }));
      setTarget(null);
    } catch (err) {
      setError(apiMessage(err, t("restoreFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <CardContent className="space-y-3 px-5 py-4">
        <div className="space-y-1">
          <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
          <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>
        <ul className="divide-y rounded-lg border">
          {copies.map((copy) => (
            <li key={copy.name} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
              <DatabaseBackup className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-48 flex-1">
                <p className="text-sm font-medium">{when(copy)}</p>
                <p className="truncate font-mono text-xs text-muted-foreground" title={copy.name}>
                  {copy.name}
                  {formatBytes(copy.size_bytes, format) ? ` · ${formatBytes(copy.size_bytes, format)}` : ""}
                </p>
              </div>
              <Button variant="outline" size="sm" onClick={() => { setError(null); setTarget(copy); }}>
                <History className="size-4" />
                {t("restore")}
              </Button>
            </li>
          ))}
        </ul>
      </CardContent>

      <ConfirmDialog
        open={Boolean(target)}
        onOpenChange={(next) => !next && !pending && setTarget(null)}
        icon={History}
        tone="warning"
        title={t("confirmTitle")}
        description={target ? t("confirmBody", { when: when(target) }) : ""}
        cancelLabel={t("cancel")}
        confirmLabel={pending ? t("restoring") : t("restore")}
        pending={pending}
        error={error}
        onConfirm={restore}
      >
        {pending ? (
          <p className="flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 className="size-3.5 animate-spin" aria-hidden />
            {t("restoringNote")}
          </p>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}

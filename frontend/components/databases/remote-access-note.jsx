"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { Globe, Info } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { closeRemoteAccess } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { remoteAccessNote } from "@/lib/databases/remote-access";

export function RemoteAccessNotes({ states = [], canManage }) {
  const t = useTranslations("databases");
  const { refreshAndWait } = useRefresh();
  const [closing, setClosing] = useState(null);
  const [pending, setPending] = useState(false);

  const notes = states
    .map((state) => ({ state, kind: remoteAccessNote(state) }))
    .filter((note) => note.kind);
  if (!notes.length) return null;

  const name = (engine) => t(`engines.${engine}`);

  async function handleConfirm() {
    if (pending || !closing) return;
    setPending(true);
    try {
      await closeRemoteAccess(closing);
      await refreshAndWait();
      toast.success(t("remoteAccess.closed", { name: name(closing) }));
      setClosing(null);
    } catch (error) {
      toast.error(apiMessage(error, t("remoteAccess.closeFailed", { name: name(closing) })));
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="basis-full space-y-2">
      {notes.map(({ state, kind }) =>
        kind === "close" ? (
          <div
            key={state.engine}
            className="flex flex-col gap-2 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
          >
            <p className="flex items-start gap-2 text-sm">
              <Globe aria-hidden className="mt-0.5 size-4 shrink-0 text-warning" />
              <span>{t("remoteAccess.stillOpen", { name: name(state.engine) })}</span>
            </p>
            {canManage ? (
              <Button
                variant="outline"
                size="sm"
                className="shrink-0 self-start sm:self-auto"
                onClick={() => setClosing(state.engine)}
              >
                {t("remoteAccess.close")}
              </Button>
            ) : null}
          </div>
        ) : (
          <p key={state.engine} className="flex items-start gap-2 text-xs text-muted-foreground">
            <Info aria-hidden className="mt-0.5 size-3.5 shrink-0" />
            <span>{t("remoteAccess.openedByServer", { name: name(state.engine) })}</span>
          </p>
        ),
      )}

      <ConfirmDialog
        open={closing !== null}
        onOpenChange={(next) => !next && setClosing(null)}
        icon={Globe}
        tone="warning"
        title={closing ? t("remoteAccess.confirmTitle", { name: name(closing) }) : ""}
        description={closing ? t("remoteAccess.confirmBody", { name: name(closing) }) : ""}
        cancelLabel={t("cancel")}
        confirmLabel={pending ? t("remoteAccess.closing") : t("remoteAccess.confirm")}
        pending={pending}
        onConfirm={handleConfirm}
      />
    </div>
  );
}

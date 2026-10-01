import { useRef, useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteSystemUser } from "@/lib/api/system-users";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CopyButton } from "@/components/ui/copy-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { genericErrorMessage } from "@/lib/api/generic-error";
import { useRefresh } from "@/hooks/use-refresh";

export function DeleteSystemUserDialog({ user, open, onOpenChange, prevPage = null }) {
  const t = useTranslations("systemUsers");
  const { refreshThen, navigateThen } = useRefresh();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");
  // The row, and the ⋯ that opened this, are gone once it is deleted.
  const removed = useRef(false);

  const username = user?.username ?? "";
  const matches = confirm.trim() === username;

  function handleOpenChange(next) {
    if (!next) setConfirm("");
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    // Toasted once the row has gone from the list.
    const done = (say) => {
      const after = () => {
        removed.current = true;
        say();
        handleOpenChange(false);
        setPending(false);
      };
      // The last row on its page: go straight to the previous page rather than render
      // an empty one and redirect.
      if (prevPage) navigateThen({ page: prevPage > 1 ? prevPage : undefined }, after);
      else refreshThen(after);
    };
    try {
      await deleteSystemUser(user.id);
      done(() => toast.success(t("toast.deleted")));
    } catch (error) {
      // Deleted elsewhere: what was asked for is already true.
      if (error?.response?.status === 404) {
        done(() => toast.info(t("toast.alreadyGone", { username })));
        return;
      }
      // 422 = still owns applications; show the backend's translated message.
      toast.error(apiMessage(error, genericErrorMessage()));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("delete.title")}
      description={t("delete.description", { username })}
      cancelLabel={t("delete.cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      confirmDisabled={!matches}
      pending={pending}
      onConfirm={onConfirm}
      onCloseAutoFocus={(event) => {
        if (!removed.current) return;
        removed.current = false;
        event.preventDefault();
        document.querySelector("[data-su-add]")?.focus();
      }}
    >
      <div className="space-y-2">
        {/* Copy button, because the name must be typed exactly; matches the
            delete-application dialog. */}
        <div className="flex items-start justify-between gap-2">
          <Label htmlFor="delete-su-confirm" className="text-sm">
            {t("delete.confirmLabel", { username })}
          </Label>
          <CopyButton value={username} label={t("delete.copyUsername")} className="size-6 shrink-0" />
        </div>
        <Input
          placeholder={username}
          id="delete-su-confirm"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          autoComplete="off"
          className="font-mono"
        />
      </div>
    </ConfirmDialog>
  );
}

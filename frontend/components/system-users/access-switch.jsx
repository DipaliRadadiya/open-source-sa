import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Info, ShieldAlert } from "lucide-react";
import { setSystemUserSudo, setSystemUserSsh } from "@/lib/api/system-users";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

export function AccessSwitch({ user, field, canManage = true, sshEnforced = null }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [asked, setAsked] = useState(null);

  const checked = field === "sudo" ? user.sudo : user.ssh_access;
  const label = field === "sudo" ? t("access.sudo") : t("access.ssh");

  const shown = asked !== null && asked !== checked ? asked : checked;

  async function apply(v) {
    setBusy(true);
    setAsked(v);
    try {
      let message;
      if (field === "sudo") {
        await setSystemUserSudo(user.id, v);
        message = v ? t("toast.sudoOn") : t("toast.sudoOff");
      } else {
        await setSystemUserSsh(user.id, v);
        // Without an sshd AllowGroups line, SSH login disabled isn't true.
        message = !v && sshEnforced === false ? t("toast.sshOffNotEnforced") : v ? t("toast.sshOn") : t("toast.sshOff");
      }
      refreshThen(() => {
        toast.success(message);
        setBusy(false);
      });
    } catch (error) {
      setAsked(null);
      setBusy(false);
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { username: user.username }));
        refresh();
        return;
      }
      toast.error(apiMessage(error, field === "sudo" ? t("toast.sudoFailed") : t("toast.sshFailed")));
    }
  }

  function onToggle(v) {
    if (field === "sudo" && v) {
      setConfirmOpen(true);
      return;
    }
    apply(v);
  }

  // null is an unrecognised shell, so don't block on a guess.
  const sshBlocked =
    field === "ssh" && !checked && user.shell_allows_login === false;
  const viaSudo = field === "ssh" && user.sudo && user.shell_allows_login !== false;
  const locked = sshBlocked || viaSudo;

  return (
    <>
      <ReasonTooltip
        reason={
          !canManage
            ? null
            : sshBlocked
              ? t("sshNeedsLoginShell", { shell: user.shell_title ?? user.shell })
              : viaSudo
                ? t("sshViaSudo")
                : null
        }
      >
        <PendingSwitch
          readOnly={!canManage}
          checked={viaSudo ? true : shown}
          pending={busy}
          disabled={!canManage || locked}
          onCheckedChange={canManage && !locked ? onToggle : undefined}
          aria-label={label}
          aside={canManage && locked ? <Info className="size-3.5 text-muted-foreground" aria-hidden /> : null}
        />
      </ReasonTooltip>

      {field === "sudo" ? (
        <ConfirmDialog
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          icon={ShieldAlert}
          tone="warning"
          title={t("sudoConfirm.title")}
          description={t("sudoConfirm.description", { username: user.username })}
          cancelLabel={t("cancel")}
          confirmLabel={t("sudoConfirm.confirm")}
          pending={busy}
          onConfirm={() => {
            setConfirmOpen(false);
            apply(true);
          }}
        />
      ) : null}
    </>
  );
}

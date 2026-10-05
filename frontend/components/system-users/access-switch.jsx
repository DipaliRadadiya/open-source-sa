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

// Enabling sudo (a root grant) asks first; everything else applies immediately.
// `sshEnforced` is the list's `meta.ssh_access_enforced`: only `false` changes the SSH toast.
export function AccessSwitch({ user, field, canManage = true, sshEnforced = null }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  // The requested value, until the server agrees with it.
  const [asked, setAsked] = useState(null);

  const checked = field === "sudo" ? user.sudo : user.ssh_access;
  const label = field === "sudo" ? t("access.sudo") : t("access.ssh");

  // Shows the requested value until `router.refresh()` lands. Derived, not cleared in
  // an effect, so changes made elsewhere show through immediately.
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
        // Without an sshd AllowGroups line, "SSH login disabled" would be untrue.
        message = !v && sshEnforced === false ? t("toast.sshOffNotEnforced") : v ? t("toast.sshOn") : t("toast.sshOff");
      }
      // Toasted once the row shows it, like every other change on this page.
      refreshThen(() => {
        toast.success(message);
        setBusy(false);
      });
    } catch (error) {
      // Revert the knob: the change did not happen.
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
    // Confirm before granting root; everything else is instant.
    if (field === "sudo" && v) {
      setConfirmOpen(true);
      return;
    }
    apply(v);
  }

  // The server rejects SSH with a shell that refuses login; `shell_allows_login: null`
  // is an unrecognised shell, so it is not blocked on a guess.
  const sshBlocked =
    field === "ssh" && !checked && user.shell_allows_login === false;
  // sshd's AllowGroups always admits the sudo group, so for a sudo user this switch
  // decides nothing: shown on and locked.
  const viaSudo = field === "ssh" && user.sudo && user.shell_allows_login !== false;
  const locked = sshBlocked || viaSudo;

  return (
    <>
      {/* Both disable reasons get a sentence; permission comes first because it applies
          regardless of the shell. */}
      <ReasonTooltip
        reason={
          !canManage
            ? t("noPermission")
            : sshBlocked
              ? t("sshNeedsLoginShell", { shell: user.shell_title ?? user.shell })
              : viaSudo
                ? t("sshViaSudo")
                : null
        }
      >
        <PendingSwitch
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

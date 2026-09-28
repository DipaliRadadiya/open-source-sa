import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ShieldAlert } from "lucide-react";
import { setSystemUserSudo, setSystemUserSsh } from "@/lib/api/system-users";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";
import { genericErrorMessage } from "@/lib/api/generic-error";
import { useRefresh } from "@/hooks/use-refresh";

// Inline access toggle used in the table. `field` is "sudo" | "ssh". Applies
// immediately with a toast; read-only when !canManage. Enabling sudo (a root
// grant) asks for confirmation first — disabling and SSH stay instant.
// `sshEnforced` is the list's `meta.ssh_access_enforced`: only `false` changes
// what the SSH toast says.
export function AccessSwitch({ user, field, canManage = true, sshEnforced = null }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  // The value we asked for, until the server agrees with it.
  const [asked, setAsked] = useState(null);

  const checked = field === "sudo" ? user.sudo : user.ssh_access;
  const label = field === "sudo" ? t("access.sudo") : t("access.ssh");

  // The switch used to sit on the server's value alone, and that value only
  // changes when `router.refresh()` lands — so clicking it left the knob in its
  // old position for the whole round trip. Disabled and unmoved reads as a
  // click that failed, which is the opposite of what happened.
  //
  // Derived rather than cleared in an effect: once the server catches up,
  // `asked` equals `checked` and stops mattering by itself — which also means a
  // change made somewhere else shows through immediately instead of being
  // masked by a stale override.
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
        // "SSH login disabled" was untrue while sshd has no AllowGroups line:
        // the account could still sign in, and the banner above said so.
        message = !v && sshEnforced === false ? t("toast.sshOffNotEnforced") : v ? t("toast.sshOn") : t("toast.sshOff");
      }
      // Said once the row shows it, like every other change on this page.
      refreshThen(() => {
        toast.success(message);
        setBusy(false);
      });
    } catch (error) {
      // Put the knob back where it was: the change did not happen.
      setAsked(null);
      setBusy(false);
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { username: user.username }));
        refresh();
        return;
      }
      toast.error(apiMessage(error, genericErrorMessage()));
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

  // SSH access and a shell that refuses login are a contradiction the server
  // rejects. `shell_allows_login: null` is an unrecognised shell — unknown, not
  // refusing — so it is left alone rather than blocked on a guess.
  const sshBlocked =
    field === "ssh" && !checked && user.shell_allows_login === false;

  return (
    <>
      {/* PendingSwitch, not a private copy of it: this file grew the spinner
          and its reserved slot first, and cron and services then shipped
          without either. The shared one now owns that markup. */}
      {/* Both reasons, not just one. The switch is disabled for two causes but
          only `sshBlocked` supplied a sentence, so a view-only role saw every
          Sudo and SSH switch dead and silent — while the Add button on the same
          page explained itself. The string already existed and was translated;
          it was simply never passed. Permission first: it outranks the shell
          rule, because it stops you regardless of the shell. */}
      <ReasonTooltip
        reason={
          !canManage
            ? t("noPermission")
            : sshBlocked
              ? t("sshNeedsLoginShell", { shell: user.shell_title ?? user.shell })
              : null
        }
      >
        <PendingSwitch
          checked={shown}
          pending={busy}
          disabled={!canManage || sshBlocked}
          onCheckedChange={canManage && !sshBlocked ? onToggle : undefined}
          aria-label={label}
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

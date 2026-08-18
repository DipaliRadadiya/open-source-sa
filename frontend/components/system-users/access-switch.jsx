"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, ShieldAlert } from "lucide-react";
import { setSystemUserSudo, setSystemUserSsh } from "@/lib/api/system-users";
import { Switch } from "@/components/ui/switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";

// Inline access toggle used in the table. `field` is "sudo" | "ssh". Applies
// immediately with a toast; read-only when !canManage. Enabling sudo (a root
// grant) asks for confirmation first — disabling and SSH stay instant.
export function AccessSwitch({ user, field, canManage = true }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();
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
      if (field === "sudo") {
        await setSystemUserSudo(user.id, v);
        toast.success(v ? t("toast.sudoOn") : t("toast.sudoOff"));
      } else {
        await setSystemUserSsh(user.id, v);
        toast.success(v ? t("toast.sshOn") : t("toast.sshOff"));
      }
      router.refresh();
    } catch (error) {
      // Put the knob back where it was: the change did not happen.
      setAsked(null);
      toast.error(apiMessage(error, t("toast.failed")));
    } finally {
      setBusy(false);
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
      {/* The spinner's slot is always present, so a row does not jump sideways
          the moment someone flips a switch in it. */}
      <span className="inline-flex items-center gap-2">
        <ReasonTooltip
          reason={sshBlocked ? t("sshNeedsLoginShell", { shell: user.shell_title ?? user.shell }) : null}
        >
          <Switch
            checked={shown}
            disabled={busy || !canManage || sshBlocked}
            onCheckedChange={canManage && !sshBlocked ? onToggle : undefined}
            aria-label={label}
            aria-busy={busy}
          />
        </ReasonTooltip>
        <span className="inline-flex size-4 shrink-0 items-center justify-center">
          {busy ? <Loader2 className="size-4 animate-spin text-muted-foreground" /> : null}
        </span>
      </span>

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

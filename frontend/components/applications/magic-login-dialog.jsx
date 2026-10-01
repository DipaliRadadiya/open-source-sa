import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { KeyRound, Loader2, User } from "lucide-react";
import { createMagicLogin } from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import { launchMagicLogin } from "@/components/applications/use-magic-login";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * Choose which administrator to sign in as. Only opened when the site has none
 * or several administrators (see `useMagicLogin`). `admins` is fetched fresh on
 * every open, never cached: a stale name would lead to an unexplained refusal.
 */
export function MagicLoginDialog({ appId, admins, open, onOpenChange }) {
  const t = useTranslations("applications.magicLogin");
  const [pendingId, setPendingId] = useState(null);

  async function signIn(admin) {
    // The chosen row's button shows the wait; the tab opens once the login URL is
    // ready.
    setPendingId(admin.id);
    try {
      const session = await createMagicLogin(appId, admin.id);
      launchMagicLogin(session);
      onOpenChange?.(false);
    } catch (e) {
      toast.error(apiMessage(e, t("failed")));
    } finally {
      setPendingId(null);
    }
  }

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      icon={KeyRound}
      title={t("title")}
      description={t("subtitle")}
      footer={
        <Button type="button" variant="outline" onClick={() => onOpenChange?.(false)}>
          {t("cancel")}
        </Button>
      }
    >
      {admins.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("none")}</p>
      ) : (
        <ul className="space-y-2">
          {admins.map((admin) => (
            <li
              key={admin.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
            >
              <div className="flex min-w-0 items-center gap-2">
                <User className="size-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{admin.name}</p>
                  <p className="truncate font-mono text-xs text-muted-foreground">
                    {admin.login}
                  </p>
                </div>
              </div>
              <Button
                type="button"
                size="sm"
                disabled={pendingId !== null}
                onClick={() => signIn(admin)}
              >
                {pendingId === admin.id && <Loader2 className="size-4 animate-spin" />}
                {pendingId === admin.id ? t("redirecting") : t("signIn")}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {/* This is impersonation, and the activity log records it by name. */}
      <p className="text-xs text-muted-foreground">{t("auditNote")}</p>
    </FormModal>
  );
}

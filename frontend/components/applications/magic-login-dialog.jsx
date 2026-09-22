import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { KeyRound, Loader2, User } from "lucide-react";
import { createMagicLogin } from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import { submitMagicLogin } from "@/lib/applications/magic-login-window";
import { openBlankTab, paintPlaceholder, discardTab } from "@/lib/browser/new-tab";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * Choose which administrator to sign in as.
 *
 * Only reached when there is a choice to make — `useMagicLogin` signs straight
 * in when the site has exactly one administrator, and opens this when it has
 * none or several.
 *
 * `admins` arrives already fetched, from the same call that decided to open
 * this. It is never cached between opens: an account that was an administrator
 * last time may not be one now, and offering a stale name means a refusal the
 * operator cannot explain.
 */
export function MagicLoginDialog({ appId, admins, open, onOpenChange }) {
  const t = useTranslations("applications.magicLogin");
  const [pendingId, setPendingId] = useState(null);

  async function signIn(admin) {
    // Synchronously, while this is still the click — see openBlankTab. The old
    // version minted the token first and opened afterwards, by which point the
    // gesture was spent and the browser was entitled to block the tab.
    const tab = openBlankTab();
    if (!tab) {
      toast.error(t("popupBlocked"));
      return;
    }

    // The same holding page the one-administrator path paints. This route is
    // one request rather than two, so the gap is shorter — but a white tab for
    // a second and a half still reads as nothing happening.
    paintPlaceholder(tab, t("redirecting"), t("action"));

    setPendingId(admin.id);
    try {
      const session = await createMagicLogin(appId, admin.id);
      submitMagicLogin(tab, session);
      onOpenChange?.(false);
    } catch (e) {
      discardTab(tab);
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
                {t("signIn")}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {/* Said plainly, because this is impersonation and the activity log
          records it by name. Someone reading the log later should not be the
          first person to learn that. */}
      <p className="text-xs text-muted-foreground">{t("auditNote")}</p>
    </FormModal>
  );
}

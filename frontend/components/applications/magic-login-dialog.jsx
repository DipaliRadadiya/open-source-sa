import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { KeyRound, Loader2, ShieldAlert, User } from "lucide-react";
import {
  getWordPressAdministrators,
  createMagicLogin,
} from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * Sign in to this WordPress site as one of its administrators.
 *
 * The list is fetched when the dialog opens, never cached: an account that was
 * an administrator last time this was used may not be one now, and offering a
 * stale name would mean a refusal the user cannot explain.
 */
export function MagicLoginDialog({ appId, open, onOpenChange }) {
  const t = useTranslations("applications.magicLogin");

  const [admins, setAdmins] = useState(null);
  const [error, setError] = useState(null);
  const [pendingId, setPendingId] = useState(null);

  // No state reset here. The launcher remounts this component on every open
  // (via `key`), so `admins` and `error` start fresh by construction — which
  // is both simpler than clearing them and what React actually wants: a
  // synchronous setState in an effect body is a cascading render.
  useEffect(() => {
    if (!open) return undefined;
    let live = true;

    getWordPressAdministrators(appId)
      .then((list) => live && setAdmins(list))
      // The reason matters here more than most: "no administrators" and "we
      // could not ask WordPress" look identical as an empty list, and only one
      // of them is the user's problem.
      .catch((e) => live && setError(apiMessage(e, t("listFailed"))));

    return () => {
      live = false;
    };
  }, [open, appId, t]);

  async function signIn(admin) {
    setPendingId(admin.id);
    try {
      const session = await createMagicLogin(appId, admin.id);

      // Submitted as a form POST, never opened as a URL with the token in the
      // query string. A token in a URL is written to the site's access log,
      // the browser's history and any outbound Referer — and this one is worth
      // a full administrator session.
      //
      // Deliberately no `noopener` in the feature string: with it, window.open
      // returns null in every browser that honours it, and the reference is
      // exactly what this needs. `opener` is severed immediately below
      // instead, which buys the same protection without losing the handle.
      const target = window.open("", "_blank");

      if (!target) {
        toast.error(t("popupBlocked"));
        return;
      }

      // The site is about to run its own code in that tab. Without this it
      // could reach back through window.opener and navigate the panel.
      try {
        target.opener = null;
      } catch {
        // Cross-origin by the time we get here on some browsers; the form
        // below still posts, and this was defence in depth rather than the
        // only thing standing between the site and the panel.
      }

      // Built through the DOM, never by writing a string of HTML. The token
      // and the URL would both be interpolated into markup otherwise, and
      // "the token is alphanumeric so it cannot break out" is an argument that
      // stops being true the day the token format changes.
      const doc = target.document;
      const form = doc.createElement("form");
      form.method = "POST";
      form.action = session.url;

      const field = doc.createElement("input");
      field.type = "hidden";
      field.name = "sv_magic_login";
      field.value = session.token;

      form.appendChild(field);
      (doc.body ?? doc.documentElement).appendChild(form);
      form.submit();

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
      {error ? (
        <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
          <ShieldAlert className="mt-0.5 size-4 shrink-0" />
          <span>{error}</span>
        </p>
      ) : admins === null ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" />
          {t("loading")}
        </p>
      ) : admins.length === 0 ? (
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

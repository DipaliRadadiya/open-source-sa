import { useCallback, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  getWordPressAdministrators,
  createMagicLogin,
} from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import {
  openBlankTab,
  submitMagicLogin,
  discardTab,
} from "@/lib/applications/magic-login-window";

/**
 * One click, and a picker only when there is something to pick.
 *
 * Most WordPress sites have a single administrator, and the dialog asked that
 * site's operator to choose from a list of one — then click again. So the
 * count decides: one administrator signs straight in, none or several opens
 * the picker.
 *
 * The count cannot be known in advance. It comes from WP-CLI on the server, so
 * the button spins briefly before either a tab appears or the dialog does. The
 * alternative was fetching an administrator list for every WordPress site on
 * the applications page, which is a WP-CLI call per row.
 *
 * Lives in a hook because both the site dashboard's button and the row menu
 * need exactly this, and the sequence below is easy to half-copy.
 */
export function useMagicLogin(appId) {
  const t = useTranslations("applications.magicLogin");
  const [pending, setPending] = useState(false);
  // Non-null while the picker is open. Carries the list already fetched, so
  // the dialog never asks WordPress a second time for what we just read.
  const [choice, setChoice] = useState(null);

  const start = useCallback(async () => {
    /*
     * Opened here, synchronously, while this is still the click. Everything
     * below awaits, and an await costs the user gesture that `window.open` is
     * allowed by.
     */
    const tab = openBlankTab();
    if (!tab) {
      toast.error(t("popupBlocked"));
      return;
    }

    setPending(true);
    try {
      const admins = await getWordPressAdministrators(appId);

      if (admins.length === 1) {
        const session = await createMagicLogin(appId, admins[0].id);
        submitMagicLogin(tab, session);
        return;
      }

      /*
       * None or several. Both open the dialog — and both need the tab closed
       * first, because a blank tab left behind beside a picker reads as a
       * login that half-happened.
       *
       * Zero is not merged into the error path on purpose: "this site has no
       * administrators" and "we could not ask WordPress" look identical as an
       * empty list, and only one of them is the operator's problem. The dialog
       * says the first; the catch below says the second.
       */
      discardTab(tab);
      setChoice({ admins });
    } catch (error) {
      discardTab(tab);
      toast.error(apiMessage(error, t("listFailed")));
    } finally {
      setPending(false);
    }
  }, [appId, t]);

  return {
    /** Call from a click handler. Never from an effect — see openBlankTab. */
    start,
    pending,
    choice,
    closeChoice: useCallback(() => setChoice(null), []),
  };
}

import { useCallback, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  getWordPressAdministrators,
  createMagicLogin,
} from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import { openMagicLogin } from "@/lib/applications/magic-login-window";

/**
 * Open WordPress with a minted session, once the answer is here. No fallback
 * button — see `lib/browser/new-tab.js`.
 */
export function launchMagicLogin(session) {
  openMagicLogin(session);
}

/**
 * Magic login: one administrator signs straight in; none or several opens the
 * picker. The count comes from WP-CLI, so the button shows a wait first
 * (fetching per row up front would be a WP-CLI call per site).
 *
 * Shared by the site dashboard's button and the row menu.
 */
export function useMagicLogin(appId) {
  const t = useTranslations("applications.magicLogin");
  // "fetching" while WordPress lists administrators, then "signing"; the button
  // shows which.
  const [phase, setPhase] = useState(null);
  // Non-null while the picker is open; carries the fetched list so the dialog
  // does not ask WordPress again.
  const [choice, setChoice] = useState(null);

  const start = useCallback(async () => {
    /*
     * No tab until there is somewhere to send it: the button carries the wait
     * (`phase`) and the tab opens straight onto WordPress, rather than a blank tab.
     */
    setPhase("fetching");
    try {
      const admins = await getWordPressAdministrators(appId);

      if (admins.length === 1) {
        setPhase("signing");
        const session = await createMagicLogin(appId, admins[0].id);
        launchMagicLogin(session);
        return;
      }

      /*
       * None or several: the picker. Zero is kept apart from the error path: "no
       * administrators" and "could not ask WordPress" are different problems.
       */
      setChoice({ admins });
    } catch (error) {
      toast.error(apiMessage(error, t("listFailed")));
    } finally {
      setPhase(null);
    }
  }, [appId, t]);

  return {
    /** Call from a click handler: the tab it opens rides on that click. */
    start,
    pending: phase !== null,
    phase,
    choice,
    closeChoice: useCallback(() => setChoice(null), []),
  };
}

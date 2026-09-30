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
  // "fetching" while WordPress lists its administrators, then "signing" only
  // once there is one to sign in as — the button says which wait it is.
  const [phase, setPhase] = useState(null);
  // Non-null while the picker is open. Carries the list already fetched, so
  // the dialog never asks WordPress a second time for what we just read.
  const [choice, setChoice] = useState(null);

  const start = useCallback(async () => {
    /*
     * No tab until there is somewhere to send it. Opening about:blank first
     * kept the click's permission to open a tab, but it showed a blank page
     * for the whole WP-CLI round trip — and when there were several
     * administrators it closed that tab again to show the picker. The button
     * carries the wait instead (`phase`), and the tab opens straight onto
     * WordPress.
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
       * None or several: the picker. Zero is not merged into the error path on
       * purpose — "this site has no administrators" and "we could not ask
       * WordPress" look identical as an empty list, and only one of them is the
       * operator's problem. The dialog says the first; the catch says the
       * second.
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

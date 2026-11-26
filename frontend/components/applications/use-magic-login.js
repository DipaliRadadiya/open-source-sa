import { useCallback, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  getWordPressAdministrators,
  createMagicLogin,
} from "@/lib/api/magic-login";
import { apiMessage } from "@/lib/api/error-message";
import { openMagicLogin } from "@/lib/applications/magic-login-window";

export function launchMagicLogin(session) {
  openMagicLogin(session);
}

export function useMagicLogin(appId) {
  const t = useTranslations("applications.magicLogin");
  const [phase, setPhase] = useState(null);
  const [choice, setChoice] = useState(null);

  const start = useCallback(async () => {
    setPhase("fetching");
    try {
      const admins = await getWordPressAdministrators(appId);

      if (admins.length === 1) {
        setPhase("signing");
        const session = await createMagicLogin(appId, admins[0].id);
        launchMagicLogin(session);
        return;
      }

      setChoice({ admins });
    } catch (error) {
      toast.error(apiMessage(error, t("listFailed")));
    } finally {
      setPhase(null);
    }
  }, [appId, t]);

  return {
    start,
    pending: phase !== null,
    phase,
    choice,
    closeChoice: useCallback(() => setChoice(null), []),
  };
}

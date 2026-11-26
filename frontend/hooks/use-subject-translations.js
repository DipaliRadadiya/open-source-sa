import { useMemo } from "react";
import { useTranslations } from "next-intl";

export function useSubjectTranslations(base, override, subject) {
  const tBase = useTranslations(base);
  const tOverride = useTranslations(override);
  return useMemo(() => {
    const own = (key) => subject === "database" && tOverride.has(key);
    return Object.assign((key, values) => (own(key) ? tOverride(key, values) : tBase(key, values)), {
      rich: (key, values) => (own(key) ? tOverride.rich(key, values) : tBase.rich(key, values)),
      has: (key) => own(key) || tBase.has(key),
    });
  }, [tBase, tOverride, subject]);
}

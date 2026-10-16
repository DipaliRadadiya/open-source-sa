import { useMemo } from "react";
import { useTranslations } from "next-intl";

// The backup screens are written for an application. For a database, a sentence
// that names the application is swapped for the database's own; every other
// string (schedule, storage, buttons) is shared.
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

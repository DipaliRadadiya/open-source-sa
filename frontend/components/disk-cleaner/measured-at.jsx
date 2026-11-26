import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";

export function MeasuredAt({ at }) {
  const t = useTranslations("diskCleaner");
  const [minutes, setMinutes] = useState(0);

  useEffect(() => {
    const measured = new Date(at).getTime();
    const tick = () => setMinutes(Math.floor((Date.now() - measured) / 60000));

    tick();
    const id = setInterval(tick, 30000);
    return () => clearInterval(id);
  }, [at]);

  return (
    <span className="mt-1 block text-xs">
      {minutes < 1 ? t("list.measuredJustNow") : t("list.measuredAgo", { minutes })}
    </span>
  );
}

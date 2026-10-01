import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";

// A re-measure button instead of polling, because the scan walks the filesystem.
export function MeasuredAt({ at }) {
  const t = useTranslations("diskCleaner");
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [minutes, setMinutes] = useState(0);

  useEffect(() => {
    const measured = new Date(at).getTime();
    const tick = () => setMinutes(Math.floor((Date.now() - measured) / 60000));

    tick();
    const id = setInterval(tick, 30000);
    return () => clearInterval(id);
  }, [at]);

  return (
    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
      <span>{minutes < 1 ? t("list.measuredJustNow") : t("list.measuredAgo", { minutes })}</span>
      <button
        type="button"
        className="inline-flex items-center gap-1 underline underline-offset-4 disabled:opacity-50"
        disabled={pending}
        onClick={() => startTransition(() => router.refresh())}
      >
        <RotateCw className={pending ? "size-3 animate-spin" : "size-3"} />
        {t("list.rescan")}
      </button>
    </p>
  );
}

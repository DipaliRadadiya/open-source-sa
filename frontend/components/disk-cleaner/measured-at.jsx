"use client";

import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";

/**
 * How old these sizes are, and a way to re-measure.
 *
 * The list is a snapshot taken when the page rendered. Left open it silently
 * ages, and you can press "Clean up 291 MB" against a number measured an hour
 * ago. Saying the age out loud is cheaper and more honest than polling — the
 * scan walks the filesystem, so re-running it every few seconds on an idle tab
 * would cost real work for nobody's benefit.
 */
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

import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { phpMismatch } from "@/lib/cron-jobs/php-mismatch";

// Under the command: this site's job still runs another PHP than the site does.
export function CronjobPhpNote({ job, applications, className }) {
  const t = useTranslations("cronJobs.phpMismatch");
  const mismatch = phpMismatch(job, applications);
  if (!mismatch) return null;
  const { current, target } = mismatch;
  const text = !target
    ? t("unknownTarget")
    : current === "default"
      ? t("fromDefault", { target })
      : t("fromVersion", { current: current ?? "?", target });
  return (
    // whitespace-normal: table cells do not wrap, so the note ran past its column into the switch.
    <p className={cn("flex items-start gap-1 text-xs whitespace-normal text-warning", className)}>
      <TriangleAlert className="mt-0.5 size-3 shrink-0" aria-hidden />
      <span>{text}</span>
    </p>
  );
}

import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { switchApplicationCronjobsPhp } from "@/lib/api/applications";
import { cronjobsToReviewSchema } from "@/lib/schemas/cronjob-php";
import { apiMessage } from "@/lib/api/error-message";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function useCronPhpReview({ appId, canManageCron }) {
  const [review, setReview] = useState(null);

  function offer(data, version) {
    const jobs = cronjobsToReviewSchema.parse(data?.cronjobs_to_review ?? []);
    if (canManageCron && jobs.length > 0) setReview({ version, jobs });
  }

  const dialog = review ? (
    <CronPhpReviewDialog appId={appId} review={review} onDone={() => setReview(null)} />
  ) : null;

  return { offer, dialog };
}

function CronPhpReviewDialog({ appId, review, onDone }) {
  const t = useTranslations("applications.php.cronReview");
  const { version, jobs } = review;
  const [ticked, setTicked] = useState(() => new Set(jobs.filter((job) => job.suggested).map((job) => job.id)));
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  function toggle(id, on) {
    setTicked((prev) => {
      const next = new Set(prev);
      if (on) next.add(id);
      else next.delete(id);
      return next;
    });
  }

  async function onConfirm() {
    setPending(true);
    setError(null);
    try {
      await switchApplicationCronjobsPhp(appId, { version, cronjobIds: jobs.filter((job) => ticked.has(job.id)).map((job) => job.id) });
      toast.success(t("switched", { count: ticked.size, version }));
      onDone();
    } catch (err) {
      setError(apiMessage(err, t("failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open
      onOpenChange={(next) => !next && !pending && onDone()}
      icon={Clock}
      title={t("title", { version })}
      description={t("description", { version })}
      cancelLabel={t("notNow")}
      confirmLabel={t("confirm", { count: ticked.size })}
      confirmDisabled={ticked.size === 0}
      pending={pending}
      error={error}
      onConfirm={onConfirm}
    >
      <ul className="max-h-80 divide-y overflow-y-auto rounded-lg border">
        {jobs.map((job) => {
          const id = `cron-php-${job.id}`;
          return (
            <li key={job.id}>
              <label htmlFor={id} className="flex cursor-pointer items-start gap-3 px-3 py-2.5">
                <Checkbox
                  id={id}
                  className="mt-0.5"
                  checked={ticked.has(job.id)}
                  disabled={pending}
                  onCheckedChange={(value) => toggle(job.id, value === true)}
                />
                <span className="min-w-0 space-y-1">
                  <span className="block text-sm font-medium wrap-anywhere">{job.name}</span>
                  <span className="block text-xs text-muted-foreground">
                    {job.php_version === "default" ? t("fromDefault", { to: version }) : t("fromVersion", { from: job.php_version ?? "?", to: version })}
                  </span>
                  <span className="block font-mono text-xs break-all text-muted-foreground">{job.new_command}</span>
                </span>
              </label>
            </li>
          );
        })}
      </ul>
    </ConfirmDialog>
  );
}

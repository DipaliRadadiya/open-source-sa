import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, TriangleAlert } from "lucide-react";
import { installSupervisor } from "@/lib/api/workers";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";

// Shown before the form: Create would otherwise answer 202 and start the install. Not a blocker.
export function SupervisorMissingAlert({ appId, canManage }) {
  const t = useTranslations("applications.workers");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [queued, setQueued] = useState(false);

  async function install() {
    setPending(true);
    try {
      await installSupervisor(appId);
      toast.info(t("supervisor.installing"));
      // The banner stays (now "installing") until a refresh shows supervisord
      // present; never claim success here.
      setQueued(true);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("supervisor.installFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="rounded-lg border border-warning/30 bg-warning/5 p-3">
      <p className="flex items-start gap-2 text-sm text-warning">
        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
        <span>{queued ? t("supervisor.installing") : t("supervisor.missing")}</span>
      </p>
      {canManage && !queued ? (
        <Button
          size="sm"
          variant="outline"
          className="mt-2"
          onClick={install}
          disabled={pending}
        >
          {pending ? <Loader2 className="size-4 animate-spin" /> : null}
          {t("supervisor.install")}
        </Button>
      ) : null}
    </div>
  );
}

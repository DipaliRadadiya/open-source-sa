"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, TriangleAlert } from "lucide-react";
import { installSupervisor } from "@/lib/api/workers";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";

/**
 * Says supervisord is missing BEFORE someone fills in a worker form.
 *
 * Without this the page looked entirely normal on a server that cannot run a
 * worker at all: the empty state, the presets and the Create button were all
 * there, and the only way to discover the problem was to complete the form and
 * press Create — at which point `POST /workers` answers 202 and quietly starts
 * an apt install instead of creating anything.
 *
 * The create dialog handles that 202 correctly and always did, so this is not a
 * correctness fix; it moves the discovery to the top of the screen, where it
 * costs nothing to read.
 *
 * Deliberately NOT a blocker. The form still works — pressing Create on a box
 * without supervisord starts the same install — so this adds a door rather than
 * closing one.
 */
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
      // The banner stays, saying the install is running. It can only be
      // cleared by the services list agreeing supervisord is there, which is a
      // refresh away and minutes off — replacing it with a success message
      // would claim a finished install nobody has witnessed.
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

import { useCallback, useImperativeHandle, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleAlert, CircleCheck, GitCommitHorizontal, Loader2, RotateCw, Rocket } from "lucide-react";
import { cn } from "@/lib/utils";
import { fetchDeployment, redeployDeployment } from "@/lib/api/deployment";
import { deploymentResponseSchema } from "@/lib/schemas/deploy-history";
import { apiMessage } from "@/lib/api/error-message";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { EmptyState } from "@/components/data-table/empty-state";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

// `duration` is a count of seconds. Printed raw it read as a stray "60" in a
// row of words, which looks like an id rather than how long the deploy took.
function humanDuration(seconds) {
  // Null until the deploy finishes — and `Number(null)` is 0, not NaN, so a
  // running deploy printed a confident "0s".
  if (seconds === null || seconds === undefined || seconds === "") return null;
  const total = Number(seconds);
  if (!Number.isFinite(total) || total < 0) return null;
  if (total < 60) return `${total}s`;
  const minutes = Math.floor(total / 60);
  const rest = total % 60;
  return rest ? `${minutes}m ${rest}s` : `${minutes}m`;
}

const TONE = {
  succeeded: { icon: CircleCheck, badge: "success", dot: "text-success" },
  failed: { icon: CircleAlert, badge: "destructive", dot: "text-destructive" },
  running: { icon: Loader2, badge: "warning", dot: "text-warning", spin: true },
  queued: { icon: Loader2, badge: "secondary", dot: "text-muted-foreground", spin: true },
};

/**
 * Every deploy this site has run.
 *
 * The screen had a Deploy button and nothing else: no way to tell whether the
 * last one worked, what it built, or why it failed. The build output is the
 * thing people actually come for, and the API sends it only on the detail call
 * — so a row opens it rather than the list carrying fifty build logs nobody
 * asked for.
 */
export function DeployHistoryCard({ ref, applicationId, deployments, canManage }) {
  const t = useTranslations("applications.deployment.history");
  const router = useRouter();
  const [open, setOpen] = useState(null);
  const [loading, setLoading] = useState(false);
  const [busyId, setBusyId] = useState(null);

  // The API decides what still counts as running, so this does not need its own
  // copy of which statuses are terminal.
  const running = deployments.some((deployment) => deployment.in_flight);

  const show = useCallback(
    async (deployment) => {
      setOpen({ ...deployment, output: null });
      setLoading(true);
      try {
        const { data } = await fetchDeployment(applicationId, deployment.id);
        const parsed = deploymentResponseSchema.safeParse(data);
        if (parsed.success) setOpen(parsed.data.deployment);
      } catch (error) {
        toast.error(apiMessage(error, t("logFailed")));
      } finally {
        setLoading(false);
      }
    },
    [applicationId, t],
  );

  // The failure banner up on the Deploy card opens a build log from here: the
  // evidence for "the setup script failed" lives in this dialog, and making
  // someone scroll down and guess which row to click is the gap this closes.
  //
  // Imperative rather than an `openId` prop because the trigger is a click, not
  // a render — routing it through state would mean opening a dialog from an
  // effect, which is both a lint error and a lie about what happened.
  useImperativeHandle(ref, () => ({ show }), [show]);

  async function redeploy(deployment) {
    setBusyId(deployment.id);
    try {
      await redeployDeployment(applicationId, deployment.id);
      toast.success(t("redeployStarted"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("redeployFailed")));
    } finally {
      setBusyId(null);
    }
  }

  if (deployments.length === 0) {
    return <EmptyState icon={Rocket} title={t("empty.title")} description={t("empty.description")} />;
  }

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      {/* A deploy takes a minute or two. Without this the row says "Running"
          until someone reloads, which reads as stuck. */}
      {running ? <AutoRefresh intervalMs={5000} stopAfterMs={900000} /> : null}

      <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
        <div className="space-y-1">
          <p className="font-semibold">{t("title")}</p>
          <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>
      </div>

      <CardContent className="p-0">
        <ul className="divide-y">
          {deployments.map((deployment) => {
            const tone = TONE[deployment.status] ?? TONE.queued;
            const Icon = tone.icon;
            return (
              <li
                key={deployment.id}
                className="flex flex-wrap items-center gap-3 px-5 py-3.5 transition-colors hover:bg-muted/40"
              >
                <Icon className={cn("size-4 shrink-0", tone.dot, tone.spin && "animate-spin")} />

                <button
                  type="button"
                  onClick={() => show(deployment)}
                  className="min-w-0 flex-1 text-left"
                >
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-medium">
                      {deployment.commit_message || t("noMessage")}
                    </span>
                    <Badge variant={tone.badge} className="font-normal">
                      {deployment.status_title ?? deployment.status}
                    </Badge>
                  </span>
                  <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    {deployment.commit_short ? (
                      <span className="flex items-center gap-1 font-mono">
                        <GitCommitHorizontal className="size-3" />
                        {deployment.commit_short}
                      </span>
                    ) : null}
                    {deployment.branch ? <span className="font-mono">{deployment.branch}</span> : null}
                    <span>{deployment.trigger_title ?? deployment.trigger}</span>
                    {/* Null for a push — nobody pressed anything, so naming an
                        actor would be an invention. */}
                    <span>{deployment.user?.username ?? t("system")}</span>
                    {deployment.created_at_human ? <span>{deployment.created_at_human}</span> : null}
                    {humanDuration(deployment.duration) ? (
                      <span>{humanDuration(deployment.duration)}</span>
                    ) : null}
                  </span>
                </button>

                <ReasonTooltip reason={canManage ? null : t("noPermission")}>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!canManage || busyId === deployment.id || running}
                    onClick={() => redeploy(deployment)}
                  >
                    {busyId === deployment.id ? (
                      <Loader2 className="size-4 animate-spin" />
                    ) : (
                      <RotateCw className="size-4" />
                    )}
                    {t("redeploy")}
                  </Button>
                </ReasonTooltip>
              </li>
            );
          })}
        </ul>
      </CardContent>

      <Dialog open={open !== null} onOpenChange={(next) => (next ? null : setOpen(null))}>
        <DialogContent className="grid-rows-[auto_minmax(0,1fr)] h-[80vh] sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle className="truncate">
              {open?.commit_message || t("noMessage")}
            </DialogTitle>
            <DialogDescription>
              {[open?.status_title, open?.branch, open?.commit_short, open?.created_at_human]
                .filter(Boolean)
                .join(" · ")}
            </DialogDescription>
          </DialogHeader>

          <div className="flex min-h-0 flex-col overflow-hidden rounded-lg border border-console-border bg-console">
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-console-border px-3 py-1.5">
              <span className="font-mono text-[11px] text-console-muted">{t("output")}</span>
              {open?.output ? (
                <CopyButton
                  value={open.output}
                  label={t("copy")}
                  className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                />
              ) : null}
            </div>
            <pre className="console-scroll min-h-0 flex-1 overflow-auto p-3 font-mono text-xs leading-6 text-console-foreground">
              {loading ? t("loadingLog") : (open?.output ?? t("noOutput"))}
            </pre>
          </div>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

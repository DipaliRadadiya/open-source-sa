import { useState } from "react";
import { useTranslations } from "next-intl";
import { ShieldCheck, Loader2, CircleCheck, CircleX } from "lucide-react";
import { cn } from "@/lib/utils";
import { testServiceConfig } from "@/lib/api/services";
import { LEVEL_CLASS, lineLevel } from "@/lib/logs/severity";
import { configTestResponseSchema } from "@/lib/schemas/service";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

/**
 * "Test configuration" — nginx -t and friends. Read-only: it never reloads,
 * which is the whole value. You ask whether a change is safe *before* applying
 * it, instead of finding out by taking the site down.
 *
 * Only rendered where the API says `testable`; a service with no real test is
 * not given an invented one.
 */
export function ConfigTestButton({ service, canManage }) {
  const t = useTranslations("services");
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState(null);

  async function run() {
    setPending(true);
    try {
      const { data } = await testServiceConfig(service.key);
      const parsed = configTestResponseSchema.safeParse(data);
      setResult(
        parsed.success
          ? parsed.data.config_test
          : { ok: false, output: t("configTest.unreadable") },
      );
    } catch (error) {
      setResult({ ok: false, output: apiMessage(error, t("configTest.failed")) });
    } finally {
      setPending(false);
    }
  }

  const disabled = !canManage || pending;

  return (
    <>
      <Tooltip>
        <TooltipTrigger asChild>
          <span tabIndex={disabled ? 0 : -1} className="inline-flex">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              disabled={disabled}
              onClick={run}
              aria-label={t("configTest.action")}
            >
              {/* A shield, not another document: next to the log icon a
                  clipboard read as the same rectangle, and the row is scanned
                  by silhouette before anything else. */}
              {pending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <ShieldCheck className="size-4" />
              )}
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent>
          {canManage ? t("configTest.action") : t("noPermission")}
        </TooltipContent>
      </Tooltip>

      <Dialog open={result !== null} onOpenChange={(open) => !open && setResult(null)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            {/* Icon beside the title, not above it — same markup as
                ConfirmDialog, which is the panel's one header shape. */}
            <div className="flex min-w-0 items-center gap-3">
              <span
                className={cn(
                  "flex size-10 shrink-0 items-center justify-center rounded-full",
                  result?.ok
                    ? "bg-success/10 text-success"
                    : "bg-destructive/10 text-destructive",
                )}
              >
                {result?.ok ? (
                  <CircleCheck className="size-5" />
                ) : (
                  <CircleX className="size-5" />
                )}
              </span>
              <DialogTitle>
                {result?.ok
                  ? t("configTest.okTitle", { name: service.label })
                  : t("configTest.failTitle", { name: service.label })}
              </DialogTitle>
            </div>
            <DialogDescription className="pt-1">
              {result?.ok ? t("configTest.okBody") : t("configTest.failBody")}
            </DialogDescription>
          </DialogHeader>

          {/* The tool's own words, verbatim: it names the offending file and
              line, which is the entire point. Summarising it would throw away
              the only part that fixes anything.
              Rendered on the console surface with the log viewer's severity
              tints — this IS terminal output, and the panel already has one
              honest way to display that. */}
          {result?.output ? (
            <div className="overflow-hidden rounded-lg border border-console-border bg-console">
              {/* A strip of its own rather than a button floating over the
                  output: on the dark canvas it was invisible, and it sat on top
                  of the first line — which is the line that names the problem. */}
              <div className="flex items-center justify-between border-b border-console-border px-3 py-1.5">
                <span className="font-mono text-[11px] uppercase tracking-wide text-console-muted">
                  {service.unit}
                </span>
                <CopyButton
                  value={result.output}
                  label={t("configTest.copyOutput")}
                  className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                />
              </div>
              <pre className="console-scroll max-h-72 overflow-auto p-3 font-mono text-xs leading-6">
                {result.output.split("\n").map((line, i) => (
                  <div key={i} className={cn("text-console-foreground", LEVEL_CLASS[lineLevel(line)])}>
                    {line || " "}
                  </div>
                ))}
              </pre>
            </div>
          ) : null}
        </DialogContent>
      </Dialog>
    </>
  );
}

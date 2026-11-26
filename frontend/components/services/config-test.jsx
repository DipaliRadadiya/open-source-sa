import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { CircleCheck, CircleX, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { testServiceConfig } from "@/lib/api/services";
import { LEVEL_CLASS, lineLevel } from "@/lib/logs/severity";
import { configTestResponseSchema } from "@/lib/schemas/service";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiMessage } from "@/lib/api/error-message";

// A Dialog inside DropdownMenuContent unmounts with the menu, so it's rendered as a sibling.
export function useConfigTest(service) {
  const t = useTranslations("services");
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState(null);
  // A test closed early must not reopen the dialog or overwrite a newer run.
  const runId = useRef(0);

  async function run() {
    const id = ++runId.current;
    setResult(null);
    setOpen(true);
    setPending(true);
    let next;
    try {
      const { data } = await testServiceConfig(service.key);
      const parsed = configTestResponseSchema.safeParse(data);
      next = parsed.success
        ? parsed.data.config_test
        : { ok: false, output: t("configTest.unreadable") };
    } catch (error) {
      next = { ok: false, output: apiMessage(error, t("configTest.failed")) };
    }
    if (id !== runId.current) return;
    setResult(next);
    setPending(false);
  }

  function dismiss() {
    runId.current += 1;
    setOpen(false);
    setPending(false);
  }

  return { run, open, pending, result, dismiss };
}

export function ConfigTestDialog({ service, open, pending, result, onDismiss }) {
  const t = useTranslations("services");

  return (
    <>
      <Dialog open={open} onOpenChange={(next) => !next && onDismiss()}>
        <DialogContent className="sm:max-w-2xl" aria-busy={pending}>
          <DialogHeader>
            <div className="flex min-w-0 items-center gap-3">
              <span
                className={cn(
                  "flex size-10 shrink-0 items-center justify-center rounded-full",
                  pending
                    ? "bg-muted text-muted-foreground"
                    : result?.ok
                      ? "bg-success/10 text-success"
                      : "bg-destructive/10 text-destructive",
                )}
              >
                {pending ? (
                  <Loader2 className="size-5 animate-spin" />
                ) : result?.ok ? (
                  <CircleCheck className="size-5" />
                ) : (
                  <CircleX className="size-5" />
                )}
              </span>
              <DialogTitle>
                {pending
                  ? t("configTest.runningTitle", { name: service.label })
                  : result?.ok
                    ? t("configTest.okTitle", { name: service.label })
                    : t("configTest.failTitle", { name: service.label })}
              </DialogTitle>
            </div>
            <DialogDescription className="pt-1">
              {pending
                ? t("configTest.runningBody")
                : result?.ok
                  ? t("configTest.okBody")
                  : t("configTest.failBody")}
            </DialogDescription>
          </DialogHeader>

          {!pending && result?.output ? (
            <div className="overflow-hidden rounded-lg border border-console-border bg-console">
              <div className="flex items-center justify-between border-b border-console-border px-3 py-1.5">
                <span className="font-mono text-xs uppercase tracking-wide text-console-muted">
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

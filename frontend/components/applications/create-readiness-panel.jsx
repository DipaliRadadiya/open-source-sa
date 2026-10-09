import { CheckCircle2, CircleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";

// Container query, not a breakpoint: the card is narrow at wide viewports and wide at narrow ones.
// `className` lets the side column cap its height: then only the list scrolls and the
// footer buttons stay on screen (on a short laptop they had been below the fold, 7 Oct).
export function CreateReadinessPanel({ items = [], onSelectItem, footer = null, className }) {
  const t = useTranslations("applications");
  const done = items.filter((item) => item.ready).length;
  // What is still missing comes first: it is what the reader has to act on (7 Oct).
  const ordered = [...items.filter((item) => !item.ready), ...items.filter((item) => item.ready)];
  const complete = items.length > 0 && done === items.length;

  return (
    <Card className={cn("@container gap-0 py-0", className)}>
      <CardHeader className="shrink-0 space-y-1 border-b py-4">
        <div className="flex items-center justify-between gap-3">
          <CardTitle as="h2">{t("guided.stageReview")}</CardTitle>
          <Badge variant={complete ? "success" : "muted"} className="font-normal">
            {complete ? t("readiness.ready") : t("readiness.needsAttention")}
          </Badge>
        </div>
        <CardDescription>
          {complete ? t("readiness.readyHint") : t("readiness.incompleteHint")}
        </CardDescription>
        {/* A progress bar shows how much is left, which the sentence alone does not. */}
        {items.length ? (
          <div className="space-y-1.5 pt-1">
            <div className="h-1.5 overflow-hidden rounded-full bg-primary/10">
              <div
                className={cn(
                  "h-full rounded-full transition-[width] duration-300",
                  complete ? "bg-success" : "bg-primary",
                )}
                style={{ width: `${Math.round((done / items.length) * 100)}%` }}
              />
            </div>
            <p className="text-xs text-muted-foreground tabular-nums">
              {t("readiness.progress", { done, total: items.length })}
            </p>
          </div>
        ) : null}
      </CardHeader>
      <CardContent className="grid min-h-0 flex-1 content-start gap-x-6 gap-y-2.5 overflow-y-auto py-4 @md:grid-cols-2 @3xl:grid-cols-3 @6xl:grid-cols-4">
        {ordered.map((item) => (
          <div
            key={item.key}
            className="grid grid-cols-[1rem_minmax(0,1fr)] items-start gap-2 text-sm"
          >
            <span className={item.ready ? "mt-0.5 text-success" : "mt-0.5 text-warning"}>
              {item.ready ? (
                <CheckCircle2 className="size-4" aria-hidden />
              ) : (
                <CircleAlert className="size-4" aria-hidden />
              )}
            </span>
            <div className="min-w-0">
              {item.ready ? (
                <p className="font-medium">{item.label}</p>
              ) : onSelectItem ? (
                <button
                  type="button"
                  onClick={() => onSelectItem(item.target ?? item.key)}
                  className="rounded-sm text-left font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  {t(item.invalid ? "readiness.fix" : "readiness.complete", { field: item.label })}
                </button>
              ) : (
                <p className="font-medium">
                  {t(item.invalid ? "readiness.fix" : "readiness.complete", { field: item.label })}
                </p>
              )}
              {item.ready ? <p className="truncate text-muted-foreground">{item.value}</p> : null}
            </div>
          </div>
        ))}
      </CardContent>
      {footer ? (
        <CardFooter className="shrink-0 gap-2 py-3 [&>*]:flex-1">{footer}</CardFooter>
      ) : null}
    </Card>
  );
}

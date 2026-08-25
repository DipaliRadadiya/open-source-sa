import { CheckCircle2, CircleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * The readiness checklist beside (or under) the create form.
 *
 * It is a 20rem column at `lg` and up, and the full width of the page below
 * that — where a single stack of short lines left most of the card empty. The
 * list flows into columns once the card is wide enough to hold them, so it fills
 * whatever width it is given.
 *
 * A container query, not a breakpoint: this card is narrow at wide viewports
 * (it is the sidebar) and wide at narrow ones (it is stacked). The viewport says
 * nothing useful about it.
 */
export function CreateReadinessPanel({ items = [], onSelectItem }) {
  const t = useTranslations("applications");
  const complete = items.every((item) => item.ready);

  return (
    <Card className="@container border-primary/20 bg-primary/[0.02]">
      <CardHeader className="space-y-1 pb-3">
        <div className="flex items-center justify-between gap-3">
          <CardTitle className="text-base">{t("guided.stageReview")}</CardTitle>
          <Badge variant={complete ? "success" : "secondary"} className="font-normal">
            {complete ? t("readiness.ready") : t("readiness.needsAttention")}
          </Badge>
        </div>
        <CardDescription>
          {complete ? t("readiness.readyHint") : t("readiness.incompleteHint")}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-x-6 gap-y-2 @md:grid-cols-2 @3xl:grid-cols-3 @6xl:grid-cols-4">
        {items.map((item) => (
          <div
            key={item.key}
            className="grid grid-cols-[1rem_minmax(0,1fr)] items-start gap-2 text-sm"
          >
            <span className={item.ready ? "mt-0.5 text-success" : "mt-0.5 text-muted-foreground"}>
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
                  {t("readiness.complete", { field: item.label })}
                </button>
              ) : (
                <p className="font-medium">
                  {t("readiness.complete", { field: item.label })}
                </p>
              )}
              {item.ready ? <p className="truncate text-muted-foreground">{item.value}</p> : null}
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

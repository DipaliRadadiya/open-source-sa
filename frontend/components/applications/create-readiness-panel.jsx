import { CheckCircle2, CircleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export function CreateReadinessPanel({ items = [] }) {
  const t = useTranslations("applications");
  const complete = items.every((item) => item.ready);
  const visibleItems = items;

  return <Card className="border-primary/20 bg-primary/[0.02]">
    <CardHeader className="space-y-1 pb-3">
      <div className="flex items-center justify-between gap-3"><CardTitle className="text-base">{t("guided.stageReview")}</CardTitle><Badge variant={complete ? "success" : "secondary"} className="font-normal">{complete ? t("readiness.ready") : t("readiness.needsAttention")}</Badge></div>
      <CardDescription>{complete ? t("readiness.readyHint") : t("readiness.incompleteHint")}</CardDescription>
    </CardHeader>
    <CardContent className="space-y-2">
      {visibleItems.map((item) => <div key={item.key} className="grid grid-cols-[1rem_minmax(0,1fr)] items-start gap-2 text-sm"><span className={item.ready ? "mt-0.5 text-success" : "mt-0.5 text-muted-foreground"}>{item.ready ? <CheckCircle2 className="size-4" aria-hidden /> : <CircleAlert className="size-4" aria-hidden />}</span><div className="min-w-0"><p className="font-medium">{item.ready ? item.label : t("readiness.complete", { field: item.label })}</p>{item.ready ? <p className="truncate text-muted-foreground">{item.value}</p> : null}</div></div>)}
    </CardContent>
  </Card>;
}

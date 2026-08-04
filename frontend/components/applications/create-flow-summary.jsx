import { useTranslations } from "next-intl";
import { Check, CircleDot, Code2, PackageOpen, Sparkles } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

const STEPS = ["stageType", "stageConfigure", "stageReview"];

export function CreateFlowSummary({ selected }) {
  const t = useTranslations("applications");
  const completed = selected ? 1 : 0;
  const TypeIcon = selected?.method === "git" ? Code2 : selected?.has_installer ? PackageOpen : Sparkles;

  return (
    <aside className="lg:sticky lg:top-6">
      <Card className="shadow-sm">
        <CardHeader className="pb-3"><CardTitle className="text-base">{t("guided.creatingType")}</CardTitle></CardHeader>
        <CardContent className="space-y-5">
          <ol className="space-y-4">
            {STEPS.map((step, index) => {
              const done = index < completed;
              const active = index === completed;
              return <li key={step} className="flex gap-3"><span className={cn("flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold", done && "border-primary bg-primary text-primary-foreground", active && "border-primary text-primary", !done && !active && "text-muted-foreground")} >{done ? <Check className="size-3.5" /> : index + 1}</span><span className={cn("pt-0.5 text-sm", active || done ? "font-medium text-foreground" : "text-muted-foreground")}>{t(`guided.${step}`)}</span></li>;
            })}
          </ol>
          <div className="rounded-lg border bg-muted/30 p-3">
            {selected ? <div className="flex gap-3"><span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"><TypeIcon className="size-4" /></span><div className="min-w-0"><p className="text-xs text-muted-foreground">{t("guided.creatingType")}</p><p className="truncate text-sm font-medium">{selected.title}</p><p className="mt-1 text-xs leading-5 text-muted-foreground">{selected.has_installer ? t("guided.softwareIncluded") : t("guided.bringYourOwnCode")}</p></div></div> : <p className="text-sm leading-5 text-muted-foreground">{t("guided.typeHint")}</p>}
          </div>
        </CardContent>
      </Card>
    </aside>
  );
}

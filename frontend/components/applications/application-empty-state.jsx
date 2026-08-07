import Link from "next/link";
import { useTranslations } from "next-intl";
import { CheckCircle2, CircleDot, Globe2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useBranding } from "@/components/branding-provider";

const STEP_ICONS = [CircleDot, CheckCircle2, Globe2];

export function ApplicationEmptyState({ canManage = false }) {
  const t = useTranslations("applications");
  const { name: brand } = useBranding();
  const steps = ["choose", "configure", "provision"];

  return (
    <Card className="overflow-hidden border-dashed bg-gradient-to-br from-primary/[0.07] via-background to-background shadow-none">
      <CardContent className="grid gap-8 px-5 py-8 sm:px-8 sm:py-10 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)] lg:items-center">
        <div className="max-w-xl space-y-5">
          <span className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm"><Globe2 className="size-5" /></span>
          <div className="space-y-2">
            <h2 className="text-balance text-xl font-semibold tracking-tight sm:text-2xl">{t("empty.title")}</h2>
            <p className="max-w-lg text-sm leading-6 text-muted-foreground">{t("empty.description", { brand })}</p>
          </div>
          {canManage ? <Button asChild size="lg"><Link href="/applications/create"><Plus className="size-4" />{t("create")}</Link></Button> : null}
        </div>
        <div className="rounded-xl border bg-background/85 p-4 shadow-sm sm:p-5">
          <p className="text-sm font-medium">{t("empty.guideTitle")}</p>
          <ol className="mt-4 space-y-4">
            {steps.map((step, index) => {
              const Icon = STEP_ICONS[index];
              return <li key={step} className="flex gap-3"><span className="flex size-7 shrink-0 items-center justify-center rounded-full border bg-muted text-xs font-semibold text-muted-foreground">{index + 1}</span><div className="min-w-0"><p className="flex items-center gap-1.5 text-sm font-medium"><Icon className="size-3.5 text-primary" />{t(`empty.steps.${step}.title`)}</p><p className="mt-1 text-xs leading-5 text-muted-foreground">{t(`empty.steps.${step}.description`)}</p></div></li>;
            })}
          </ol>
        </div>
      </CardContent>
    </Card>
  );
}

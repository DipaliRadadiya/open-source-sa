"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CheckCircle2, CircleDot, Globe2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { useBranding } from "@/components/branding-provider";

const STEP_ICONS = [CircleDot, CheckCircle2, Globe2];
const STEPS = ["choose", "configure", "provision"];

// `"use client"` is required: the dashboard is a Server Component and this fails at render without it.
export function ApplicationEmptyState({ canManage = false, compact = false }) {
  const t = useTranslations("applications");
  const { name: brand } = useBranding();

  if (compact) {
    return (
      <Card className="overflow-hidden border-dashed bg-gradient-to-br from-primary/[0.07] via-background to-background shadow-none">
        {/* Horizontal padding only: Card already pads vertically, and a `py-*` here
            would stack on top of it. */}
        <CardContent className="px-5 sm:px-6">
          {/* `min-w-48`, not `min-w-0`: flex-1 has a 0 basis, so beside a shrink-0
              button it would keep shrinking instead of letting the button wrap. */}
          <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
              <Globe2 className="size-5" />
            </span>
            <div className="min-w-48 flex-1 space-y-1">
              <h2 className="text-balance text-lg font-semibold tracking-tight">{t("empty.title")}</h2>
              <p className="max-w-2xl text-sm leading-6 text-muted-foreground">{t("empty.description", { brand })}</p>
            </div>
            {canManage ? (
              <Button asChild className="shrink-0">
                <Link href="/applications/create">
                  <Plus className="size-4" />
                  {t("create")}
                </Link>
              </Button>
            ) : null}
          </div>

          {/* A preview of what the next screen asks for, so no heading. */}
          <ol className="mt-4 grid gap-x-6 gap-y-3 border-t pt-4 sm:grid-cols-3">
            {STEPS.map((step, index) => {
              const Icon = STEP_ICONS[index];
              return (
                <li key={step} className="flex gap-2.5">
                  <span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-muted text-xs font-semibold text-muted-foreground">
                    {index + 1}
                  </span>
                  <div className="min-w-0">
                    <p className="flex items-center gap-1.5 text-sm font-medium">
                      <Icon className="size-3.5 shrink-0 text-primary" />
                      {t(`empty.steps.${step}.title`)}
                    </p>
                    <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
                      {t(`empty.steps.${step}.description`)}
                    </p>
                  </div>
                </li>
              );
            })}
          </ol>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="gap-0 overflow-hidden border-dashed bg-gradient-to-br from-primary/[0.07] via-background to-background py-0 shadow-none">
      <CardContent className="grid gap-8 px-5 py-8 sm:px-8 sm:py-10 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)] lg:items-center">
        <div className="max-w-xl space-y-5">
          <span className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm"><Globe2 className="size-5" /></span>
          <div className="space-y-2">
            <h2 className="text-balance text-xl font-semibold tracking-tight sm:text-2xl">{t("empty.title")}</h2>
            <p className="max-w-lg text-sm leading-6 text-muted-foreground">{t("empty.description", { brand })}</p>
          </div>
          {canManage ? (
            <Button asChild size="lg"><Link href="/applications/create"><Plus className="size-4" />{t("create")}</Link></Button>
          ) : (
            <ReasonTooltip reason={t("noPermission")}>
              <Button size="lg" disabled><Plus className="size-4" />{t("create")}</Button>
            </ReasonTooltip>
          )}
        </div>
        <div className="rounded-xl border bg-background/85 p-4 shadow-sm sm:p-5">
          <p className="text-sm font-medium">{t("empty.guideTitle")}</p>
          <ol className="mt-4 space-y-4">
            {STEPS.map((step, index) => {
              const Icon = STEP_ICONS[index];
              return <li key={step} className="flex gap-3"><span className="flex size-7 shrink-0 items-center justify-center rounded-full border bg-muted text-xs font-semibold text-muted-foreground">{index + 1}</span><div className="min-w-0"><p className="flex items-center gap-1.5 text-sm font-medium"><Icon className="size-3.5 text-primary" />{t(`empty.steps.${step}.title`)}</p><p className="mt-1 text-xs leading-5 text-muted-foreground">{t(`empty.steps.${step}.description`)}</p></div></li>;
            })}
          </ol>
        </div>
      </CardContent>
    </Card>
  );
}

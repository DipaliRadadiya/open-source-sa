"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { CheckCircle2, CircleDot, Globe2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useBranding } from "@/components/branding-provider";

const STEP_ICONS = [CircleDot, CheckCircle2, Globe2];
const STEPS = ["choose", "configure", "provision"];

/**
 * The same invitation at two sizes.
 *
 * `compact` is for the dashboard, where this card is a guest above the server's
 * own content: at full height it pushed all five live stat cards below the fold
 * on a 1280 laptop. Identical copy and identical keys either way, so the two
 * surfaces cannot drift and no new strings are needed — only the arrangement
 * changes.
 *
 * `"use client"` is load-bearing. Both hooks here are client-only, and this
 * file got away without it while its one caller was the client applications
 * table. The dashboard is a Server Component, and importing this into it
 * without the directive builds clean and fails at render.
 */
export function ApplicationEmptyState({ canManage = false, compact = false }) {
  const t = useTranslations("applications");
  const { name: brand } = useBranding();

  if (compact) {
    return (
      <Card className="overflow-hidden border-dashed bg-gradient-to-br from-primary/[0.07] via-background to-background shadow-none">
        {/* Horizontal only. Card already pads itself vertically from
            `--card-spacing`, so a `py-*` here does not replace that value — it
            stacks on it, which is where 36px of top and bottom came from. */}
        <CardContent className="px-5 sm:px-6">
          {/* The ask and its action on one line. `min-w-48` rather than
              `min-w-0`: flex-1 gives this a basis of 0, so beside a shrink-0
              button it keeps shrinking instead of letting the button wrap. */}
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

          {/* The three steps across rather than down. The stack was most of the
              card's height, and standing alone it read as a checklist to work
              through when it is really a preview of what the next screen asks
              for — which is also why it needs no heading here. */}
          <ol className="mt-4 grid gap-x-6 gap-y-3 border-t pt-4 sm:grid-cols-3">
            {STEPS.map((step, index) => {
              const Icon = STEP_ICONS[index];
              return (
                <li key={step} className="flex gap-2.5">
                  <span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-muted text-[11px] font-semibold text-muted-foreground">
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

import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ArchiveRestore, Check, Globe, Lock, Plus, Rocket } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { UsageRing } from "@/components/ui/usage-ring";

const STEPS = [
  { key: "server", icon: Check },
  { key: "app", icon: Rocket },
  { key: "domain", icon: Globe },
  { key: "https", icon: Lock },
  { key: "backups", icon: ArchiveRestore },
];

// Only on a server with no applications. Step 1 is done by definition; step 2
// is the one thing to do now; 3–5 need an application, so they say what comes
// next rather than offering buttons that would lead nowhere yet.
export async function GettingStarted({ canCreate = false, ip = null }) {
  const [t, tApplications] = await Promise.all([
    getTranslations("serverDashboard.start"),
    getTranslations("applications"),
  ]);
  const done = 1;

  return (
    <Card className="[--card-spacing:--spacing(5)]">
      <CardHeader className="flex items-center gap-4">
        <UsageRing percent={(done / STEPS.length) * 100} label={t("title")} size={52}>
          {done}/{STEPS.length}
        </UsageRing>
        <div className="min-w-0">
          <CardTitle as="h2">{t("title")}</CardTitle>
          <CardDescription className="text-sm">{t("progress", { done, total: STEPS.length })}</CardDescription>
        </div>
      </CardHeader>
      <CardContent className="px-0">
        <ol className="border-t">
          {STEPS.map(({ key, icon: Icon }, index) => {
            const complete = index < done;
            const current = index === done;
            return (
              <li
                key={key}
                aria-current={current ? "step" : undefined}
                className={cn(
                  "relative flex flex-wrap items-start gap-x-4 gap-y-2 border-b px-5 py-4 last:border-b-0",
                  current && "bg-primary/[0.05]",
                )}
              >
                {current ? <span aria-hidden className="absolute inset-y-0 left-0 w-1 rounded-r bg-primary" /> : null}
                <span
                  className={cn(
                    "mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full ring-1",
                    complete
                      ? "bg-success text-white ring-success"
                      : current
                        ? "bg-primary text-primary-foreground ring-primary"
                        : "bg-card text-muted-foreground ring-border",
                  )}
                >
                  <Icon className="size-4" aria-hidden />
                </span>
                <div className="min-w-48 flex-1">
                  <p className={cn("text-sm font-semibold", complete && "text-muted-foreground")}>
                    <span className="sr-only">{index + 1}. </span>
                    {t(`${key}.title`)}
                  </p>
                  <p className="mt-0.5 text-sm text-pretty text-muted-foreground">
                    {t(`${key}.body`, { ip: ip ?? "—" })}
                  </p>
                </div>
                {current ? (
                  canCreate ? (
                    <Button asChild size="sm">
                      <Link href="/applications/create" prefetch={false}>
                        <Plus /> {tApplications("create")}
                      </Link>
                    </Button>
                  ) : (
                    // Said in words, not behind a disabled button's tooltip.
                    <span className="max-w-60 text-xs text-muted-foreground">{tApplications("noPermission")}</span>
                  )
                ) : !complete ? (
                  <span className="shrink-0 text-xs text-muted-foreground">{t("afterApp")}</span>
                ) : null}
              </li>
            );
          })}
        </ol>
      </CardContent>
    </Card>
  );
}

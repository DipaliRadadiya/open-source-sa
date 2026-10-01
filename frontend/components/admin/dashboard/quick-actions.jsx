import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { ArrowUpCircle, Bug, PlugZap, Stethoscope, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * Shortcuts to common admin screens. Links only, never direct actions: e.g.
 * "Update panel" must go through that screen's pre-flight checks and confirmation.
 */
const ACTIONS = [
  { key: "health", icon: Stethoscope, href: "/admin/doctor" },
  { key: "update", icon: ArrowUpCircle, href: "/admin/panel-update" },
  { key: "errors", icon: Bug, href: "/admin/error-logs" },
  { key: "users", icon: Users, href: "/admin/users" },
  { key: "central", icon: PlugZap, href: "/admin/central" },
];

export async function QuickActions() {
  const t = await getTranslations("admin.quick");

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
      </div>
      {/* auto-fit so the last row fills instead of leaving a hole. */}
      <ul className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2.5 p-4">
        {ACTIONS.map(({ key, icon: Icon, href }) => (
          <li key={key}>
            {/* Centred: a full-width left-aligned label reads as a text input. */}
            <Button
              asChild
              variant="outline"
              className="group h-auto w-full justify-center gap-2 px-3 py-2.5 text-center font-medium whitespace-normal shadow-sm transition-all hover:border-primary/40 hover:bg-primary/5 hover:shadow-md active:scale-[0.98]"
            >
              <Link href={href}>
                <Icon
                  className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                  aria-hidden
                />
                <span className="min-w-0">{t(key)}</span>
              </Link>
            </Button>
          </li>
        ))}
      </ul>
    </Card>
  );
}

import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { ArrowUpCircle, Bug, PlugZap, Stethoscope, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * The five places an administrator goes, without hunting the sidebar for them.
 *
 * Links, not buttons that do the thing from here: each of these lands on a
 * screen that then asks for confirmation or shows what it is about to do.
 * "Update panel" firing an update from a dashboard tile would skip the
 * pre-flight checks and the confirmation, which is the entire safety of that
 * screen. Opening System Health does re-run the checks, because that page runs
 * them on load.
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
      {/* Across, not down: five stacked rows made a column tall enough to leave
          a void beside a short feed, and these are peers — none of them is the
          one you came for. auto-fit so the last row fills rather than leaving a
          hole at whatever count they end on. */}
      <ul className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2.5 p-4">
        {ACTIONS.map(({ key, icon: Icon, href }) => (
          <li key={key}>
            {/* The real Button, not a link styled to look like one — these were
                flat because they were borrowing three of its classes and none
                of its states. */}
            {/* Centred, not left-aligned. Full width plus a left-aligned label
                on a pale border is the shape of a text input, which is what
                these were reading as; centring the icon-and-label pair is what
                makes a button look pressable. The hover moves — border, fill
                and the icon taking on the brand colour — so it answers back. */}
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

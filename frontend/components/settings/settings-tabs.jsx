"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import {
  Server,
  ShieldCheck,
  Gauge,
  Database,
  Wrench,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { useUnsaved } from "@/components/ui/unsaved-guard";

const SECTIONS = [
  { key: "server", href: "/settings/server", icon: Server },
  { key: "security", href: "/settings/security", icon: ShieldCheck },
  { key: "performance", href: "/settings/performance", icon: Gauge },
  { key: "database", href: "/settings/database", icon: Database },
  { key: "maintenance", href: "/settings/maintenance", icon: Wrench },
];

// Looks like the Account tab bar, but every tab is a real link to its own
// route: back works, a section can be pasted to someone, and opening the
// timezone form doesn't also load the SSH one.
const TAB =
  "relative inline-flex h-auto flex-none items-center justify-center gap-2 rounded-md border border-transparent px-4 py-2 text-sm font-medium whitespace-nowrap text-foreground/60 transition-all hover:bg-background/60 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none [&_svg]:size-4 [&_svg]:shrink-0";
const TAB_ACTIVE =
  "bg-background text-foreground shadow-sm dark:border-input dark:bg-input/30";

export function SettingsTabs({ badges = {} }) {
  const t = useTranslations("settings");
  const pathname = usePathname();
  const { guardNavigation } = useUnsaved();

  return (
    // Scrolls rather than wraps on a narrow phone — a tab bar that reflows to
    // two rows stops reading as one control. The fade is the only thing saying
    // so: without it the last two sections simply look absent on a 390px screen.
    <ScrollFade className="-mx-1 px-1 pb-1">
      <nav
        aria-label={t("tabs.label")}
        className="grid w-full grid-cols-5 gap-1 rounded-lg bg-muted p-1 max-sm:inline-flex max-sm:w-fit"
      >
        {SECTIONS.map(({ key, href, icon: Icon }) => {
          const active = pathname === href;
          const badge = badges[key];
          return (
            <Link
              key={key}
              href={href}
              aria-current={active ? "page" : undefined}
              className={cn(TAB, active && TAB_ACTIVE)}
              onClick={(event) => {
                if (!active && guardNavigation(href)) event.preventDefault();
              }}
            >
              <Icon />
              {t(`tabs.${key}`)}
              {badge ? (
                <>
                  <span
                    aria-hidden
                    className={cn(
                      "size-1.5 rounded-full",
                      badge === "warning" ? "bg-warning" : "bg-primary",
                    )}
                  />
                  <span className="sr-only">{t(`tabs.needsAttention`)}</span>
                </>
              ) : null}
            </Link>
          );
        })}
      </nav>

    </ScrollFade>
  );
}

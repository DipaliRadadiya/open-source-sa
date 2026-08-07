"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { History, RotateCcw, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { ScrollFade } from "@/components/ui/scroll-fade";

const SECTIONS = [
  { key: "overview", href: "/backups", icon: ShieldCheck },
  { key: "history", href: "/backups/history", icon: History },
  { key: "restores", href: "/backups/restores", icon: RotateCcw },
];

// Same tab bar as Settings, and real links for the same reasons: back works, a
// tab can be pasted to someone, and opening coverage does not also load a
// paginated history of every run the server has ever made.
const TAB =
  "relative inline-flex h-auto min-w-0 flex-none items-center justify-center gap-1.5 rounded-md border border-transparent px-2 py-2 text-sm font-medium whitespace-nowrap text-foreground/60 transition-all hover:bg-background/60 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:gap-2 sm:px-4 [&_svg]:size-4 [&_svg]:shrink-0";
const TAB_ACTIVE =
  "bg-background text-foreground shadow-sm dark:border-input dark:bg-input/30";

export function BackupsTabs() {
  const t = useTranslations("backups");
  const pathname = usePathname();

  return (
    <ScrollFade className="-mx-1 px-1 pb-1">
      <nav
        aria-label={t("tabs.label")}
        className="grid w-full grid-cols-3 gap-1 rounded-lg bg-muted p-1 sm:w-fit sm:grid-cols-[auto_auto_auto]"
      >
        {SECTIONS.map(({ key, href, icon: Icon }) => {
          const active = pathname === href;
          return (
            <Link
              key={key}
              href={href}
              aria-current={active ? "page" : undefined}
              className={cn(TAB, active && TAB_ACTIVE)}
            >
              <Icon />
              {t(`tabs.${key}`)}
            </Link>
          );
        })}
      </nav>
    </ScrollFade>
  );
}

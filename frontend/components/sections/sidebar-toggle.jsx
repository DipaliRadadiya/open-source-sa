"use client";

import { useTranslations } from "next-intl";
import { SidebarTrigger, useSidebar } from "@/components/ui/sidebar";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * The sidebar toggle with a dynamic tooltip (Expand / Collapse) so it clearly
 * reads as a control, not a breadcrumb icon. The span wrapper forwards the
 * hover to the tooltip (SidebarTrigger doesn't forward a ref).
 */
export function SidebarToggle() {
  const t = useTranslations("common");
  const { state } = useSidebar();
  const label =
    state === "collapsed" ? t("expandSidebar") : t("collapseSidebar");

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="mr-2 inline-flex">
          <SidebarTrigger className="border bg-background shadow-xs hover:bg-accent" />
        </span>
      </TooltipTrigger>
      <TooltipContent side="right">{label}</TooltipContent>
    </Tooltip>
  );
}

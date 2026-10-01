import { useTranslations } from "next-intl";
import { SidebarTrigger, useSidebar } from "@/components/ui/sidebar";
import { ShortcutHint } from "@/components/ui/shortcut-hint";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * The sidebar toggle with a dynamic tooltip (Expand / Collapse) so it reads as a
 * control. The span wrapper forwards hover to the tooltip (SidebarTrigger does not
 * forward a ref). The tooltip also names the Cmd/Ctrl+B shortcut, per platform.
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
      <TooltipContent side="right">
        {label}
        <ShortcutHint letter="B" className="border-background/25 bg-background/15 text-background" />
      </TooltipContent>
    </Tooltip>
  );
}

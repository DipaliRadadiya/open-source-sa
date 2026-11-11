import { IntlProvider } from "use-intl";
import { TooltipProvider } from "@/components/ui/tooltip";
import { BrandingProvider } from "@/components/branding-provider";
import { ReloadPendingProvider } from "@/components/applications/reload-pending-provider";

// One bundle, so the editors and the provider share a context.
export { ReloadPendingNotice } from "@/components/applications/reload-pending-notice";
export { HideWhileReloadPending } from "@/components/applications/reload-pending-provider";
export { FirewallSection } from "@/components/applications/firewall/firewall-section";
export { BotBlockerSection } from "@/components/applications/bot-blocker/bot-blocker-section";
export { SecuritySection } from "@/components/applications/security/security-section";
export { WebRootDialog } from "@/components/applications/web-root-dialog";

export function Harness({ messages, userId = "7", canManageServices = true, initial = [], children }) {
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <BrandingProvider branding={{ name: "Panel" }}>
        <TooltipProvider>
          <ReloadPendingProvider userId={userId} canManageServices={canManageServices} initial={initial}>
            {children}
          </ReloadPendingProvider>
        </TooltipProvider>
      </BrandingProvider>
    </IntlProvider>
  );
}

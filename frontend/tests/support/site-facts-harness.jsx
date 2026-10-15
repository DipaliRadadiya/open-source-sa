import { IntlProvider } from "use-intl";
import { TooltipProvider } from "@/components/ui/tooltip";
import { SiteFactsCard } from "@/components/applications/site-facts-card";

export function SiteFactsHarness({ messages, ...props }) {
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <SiteFactsCard {...props} />
      </TooltipProvider>
    </IntlProvider>
  );
}

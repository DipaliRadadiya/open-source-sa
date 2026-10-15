import { IntlProvider } from "use-intl";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ContainerCard } from "@/components/applications/container-card";

export function ContainerCardHarness({ messages, ...props }) {
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <ContainerCard {...props} />
      </TooltipProvider>
    </IntlProvider>
  );
}

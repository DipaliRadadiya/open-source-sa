import { IntlProvider } from "use-intl";
import { BrandingProvider } from "@/components/branding-provider";
import { TooltipProvider } from "@/components/ui/tooltip";
import { CreateApplicationForm } from "@/components/applications/create-application-form";
export { sent } from "@/lib/api/applications";

export function CreateFormHarness({ messages, ...props }) {
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <BrandingProvider branding={{ name: "Panel" }}>
        <TooltipProvider>
          <CreateApplicationForm {...props} />
        </TooltipProvider>
      </BrandingProvider>
    </IntlProvider>
  );
}

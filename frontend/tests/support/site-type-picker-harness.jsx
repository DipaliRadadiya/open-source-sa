import { IntlProvider } from "use-intl";
import { TooltipProvider } from "@/components/ui/tooltip";
import { SiteTypePicker } from "@/components/applications/site-type-picker";

export function SiteTypePickerHarness({ messages, ...props }) {
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <SiteTypePicker onChange={() => {}} {...props} />
      </TooltipProvider>
    </IntlProvider>
  );
}

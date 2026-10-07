import { useForm } from "react-hook-form";
import { IntlProvider } from "use-intl";
import { Form } from "@/components/ui/form";
import { TooltipProvider } from "@/components/ui/tooltip";
import { DockerImageSetup } from "@/components/applications/docker-image-setup";

export function DockerPickerHarness({ messages, defaultValues, onForm }) {
  const form = useForm({ defaultValues });
  onForm?.(form);
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <Form {...form}>
          <DockerImageSetup form={form} onUseCompose={() => {}} />
        </Form>
      </TooltipProvider>
    </IntlProvider>
  );
}

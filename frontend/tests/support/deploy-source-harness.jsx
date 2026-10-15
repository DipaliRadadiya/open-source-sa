import { IntlProvider } from "use-intl";
import { useForm, useWatch } from "react-hook-form";
import { TooltipProvider } from "@/components/ui/tooltip";
import { DeploySourcePicker } from "@/components/applications/deploy-source-picker";
import { RecipeSummary } from "@/components/applications/recipe-summary";

export function DeploySourceHarness({ messages, siteTypes, initialType = "", initialSource }) {
  const form = useForm({ defaultValues: { site_type: initialType, docker_source: initialSource ?? "search" } });
  const values = useWatch({ control: form.control });
  return (
    <IntlProvider locale="en" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <DeploySourcePicker siteTypes={siteTypes} value={values.site_type} onChange={(value) => form.setValue("site_type", value)} form={form} initialSource={initialSource} />
        <output data-testid="deploy-values">{JSON.stringify(values)}</output>
      </TooltipProvider>
    </IntlProvider>
  );
}

export function RecipeSummaryHarness({ messages, type }) {
  return <IntlProvider locale="en" messages={messages} timeZone="UTC"><RecipeSummary type={type} /></IntlProvider>;
}

// Own bundle entry: concurrent suites must not overwrite the Docker suite's
// create-form-harness output while Node is importing it.
export { CreateFormHarness, sent } from "./create-form-harness.jsx";

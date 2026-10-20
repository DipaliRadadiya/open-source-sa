import { useState } from "react";
import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { Textarea } from "@/components/ui/textarea";
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { DockerRegistryPicker } from "@/components/applications/docker-registry-picker";

// A starting shape, not a value: shows the 127.0.0.1 rule in the form people copy.
const EXAMPLE = `services:
  app:
    image: nginx:1.27
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - data:/usr/share/nginx/html

volumes:
  data:`;

// Compose mode: the file is the whole configuration, so it comes first and is
// required (an empty one has nothing to deploy); a private registry folds below.
export function DockerComposeSetup({ form, registryOptions = [] }) {
  const t = useTranslations("applications.dockerImage");
  const registryId = useWatch({ control: form.control, name: "registry_id" }) ?? "";
  const [registryOpen, setRegistryOpen] = useState(Boolean(registryId));

  return (
    <div className="space-y-4">
      <FormField
        control={form.control}
        name="compose"
        render={({ field }) => (
          <FormItem data-field-name="compose">
            <FormLabel required>{t("composeLabel")}</FormLabel>
            <FormControl>
              <Textarea
                {...field}
                value={field.value ?? ""}
                placeholder={EXAMPLE}
                spellCheck={false}
                rows={14}
                className="min-h-72 font-mono text-xs leading-5 field-sizing-fixed placeholder:font-mono placeholder:text-xs"
              />
            </FormControl>
            <FormDescription>{t("composeHelp")}</FormDescription>
            <FormMessage />
          </FormItem>
        )}
      />
      <DockerRegistryPicker
        form={form}
        registryOptions={registryOptions}
        open={registryOpen}
        onOpenChange={setRegistryOpen}
      />
    </div>
  );
}

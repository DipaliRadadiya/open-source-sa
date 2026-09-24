"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Box, Network } from "lucide-react";
import Link from "next/link";
import { containerSettingsFormSchema } from "@/lib/schemas/docker";
import { updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Input } from "@/components/ui/input";
import { Note } from "@/components/ui/note";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * "No network — Docker's default bridge", as a value the form can hold.
 *
 * Radix reserves `""` for "nothing selected", so an empty `SelectItem` value
 * throws. One constant, used by the defaults, the item and the submit mapping,
 * because the bug here is the three of them disagreeing.
 */
const DEFAULT_NETWORK = "__default__";

/**
 * What a container site runs as — and the only place the panel's own Docker
 * networks become usable.
 *
 * The network is the field that needed a home. `docker network create` takes no
 * container, so joining one is not something the create dialog on the Docker
 * page can do; and Compose cannot discover a network the panel made, so the
 * generated compose file has to name it with `external: true` or the site
 * quietly joins a differently-prefixed network of its own. Before this card
 * existed, the Docker page could create networks that nothing the panel builds
 * was able to use.
 *
 * `image` is deliberately not editable here. Changing it pulls, and a bad
 * reference fails after the old container is already gone — that belongs with
 * the deploy path, not with a form that saves settings. Shown read-only so the
 * card still answers "what is this running".
 *
 * A sentence about downtime, because saving this recreates the container: the
 * site is briefly down, and that is not something to discover from a graph.
 */
export function ContainerCard({
  application,
  networks = [],
  canManage = false,
  className,
}) {
  const t = useTranslations("applications.container");
  const router = useRouter();
  const [saving, setSaving] = useState(false);

  const defaults = {
    container_port: application.container_port ?? 80,
    memory_limit: application.memory_limit ?? "",
    // The sentinel, not `""`. Radix refuses an empty `SelectItem` value — it
    // reserves that for "nothing selected" — so "Docker's default bridge" needs
    // a value of its own, and it has to be the SAME value in the defaults, the
    // item and the submit mapping. Using `""` here and a sentinel in the item
    // was the first version: picking the default left the form holding a string
    // that mapped to nothing, so `docker_network: "__default__"` went to the
    // API and came back as a validation error on a field the user had just set
    // to "none".
    docker_network: application.docker_network ?? DEFAULT_NETWORK,
  };

  const form = useForm({
    resolver: zodResolver(containerSettingsFormSchema),
    defaultValues: defaults,
    mode: "onBlur",
  });

  // The site's own network, kept in the list even when it is no longer on the
  // box. Dropping it would silently reset the chooser to "default bridge" and
  // show a value the site does not have — and this is exactly the case worth
  // seeing, because that site will not start.
  const missing =
    defaults.docker_network !== DEFAULT_NETWORK &&
    !networks.some((network) => network.name === defaults.docker_network);

  async function save(values) {
    setSaving(true);
    try {
      await updateContainerSettings(application.id, {
        ...values,
        memory_limit: values.memory_limit === "" ? null : values.memory_limit,
        docker_network:
          values.docker_network === DEFAULT_NETWORK
            ? null
            : values.docker_network,
      });
      toast.success(t("saved"));
      form.reset(values);
      // The Docker page's "used by" column and this site's own facts are both
      // server-rendered, so without this they keep showing page-load state.
      router.refresh();
    } catch (error) {
      if (!handleValidationError(error, form)) {
        toast.error(apiMessage(error, t("failed")));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Box className="size-4" />
          {t("title")}
        </CardTitle>
      </CardHeader>
      <Form {...form}>
        <form onSubmit={form.handleSubmit(save)}>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-muted-foreground">
                {t("image")}
              </p>
              <code className="block truncate rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                {application.image || t("noImage")}
              </code>
              <p className="text-xs text-muted-foreground">{t("imageHint")}</p>
            </div>

            <FormField
              control={form.control}
              name="docker_network"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("network")}</FormLabel>
                  <Select
                    value={field.value}
                    onValueChange={field.onChange}
                    disabled={!canManage}
                  >
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder={t("defaultBridge")} />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value={DEFAULT_NETWORK}>
                        {t("defaultBridge")}
                      </SelectItem>
                      {missing ? (
                        <SelectItem value={defaults.docker_network}>
                          {t("missingOption", {
                            name: defaults.docker_network,
                          })}
                        </SelectItem>
                      ) : null}
                      {networks.map((network) => (
                        <SelectItem key={network.name} value={network.name}>
                          {network.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription>{t("networkHint")}</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            {networks.length === 0 ? (
              // An empty chooser with no explanation reads as a broken control.
              <Note icon={Network}>
                {t("noNetworks")}{" "}
                <Link
                  href="/docker"
                  className="font-medium text-primary underline"
                >
                  {t("noNetworksLink")}
                </Link>
              </Note>
            ) : null}

            {missing ? (
              <Note icon={Network} title={t("missingTitle")}>
                {t("missingBody", { name: defaults.docker_network })}
              </Note>
            ) : null}

            {/* The name to type, said out loud.

                Compose also registers the service name — `app` in every file the
                panel generates — so two sites on one network both answer to
                `app` and Docker's DNS picks one at random. The slug alias is
                unique, and a unique name nobody is told about is no better than
                no unique name at all. */}
            {defaults.docker_network !== DEFAULT_NETWORK && !missing ? (
              <Note icon={Network} title={t("reachableTitle")}>
                {t("reachableBody", { alias: application.slug })}
              </Note>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="container_port"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("containerPort")}</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        inputMode="numeric"
                        disabled={!canManage}
                        {...field}
                      />
                    </FormControl>
                    <FormDescription>{t("containerPortHint")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="memory_limit"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("memoryLimit")}</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="512m"
                        disabled={!canManage}
                        {...field}
                      />
                    </FormControl>
                    <FormDescription>{t("memoryLimitHint")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
          </CardContent>
          <CardSaveFooter
            submit
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage
                ? t("noPermission")
                : !form.formState.isDirty
                  ? t("nothingToSave")
                  : null
            }
            onDiscard={() => form.reset(defaults)}
            saveLabel={t("saveAction")}
            // Said before the click, not discovered after it.
            note={t("restartNote")}
            savingNote={t("savingNote")}
            showReason
          />
        </form>
      </Form>
    </Card>
  );
}

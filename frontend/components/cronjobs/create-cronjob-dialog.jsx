import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, CalendarPlus } from "lucide-react";
import { createCronjobSchema, OTHER_USER } from "@/lib/schemas/cronjob";
import { createCronjob } from "@/lib/api/cronjobs";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from "@/components/ui/form";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { ScheduleField } from "@/components/cronjobs/schedule-field";
import { CommandField } from "@/components/cronjobs/command-field";

const DEFAULTS = {
  name: "",
  run_as: "",
  username: "",
  command: "",
  expression: "",
  active: true,
};

export function CreateCronjobDialog({
  open,
  onOpenChange,
  systemUsers = [],
  systemUsersFailed = false,
  schedulePresets = [],
  commandPresets = [],
  applications = [],
  placeholder,
  timezone,
  // Prefill from a duplicated job or a quick-start template. Re-seeded on open
  // so opening the dialog twice with different sources can't show stale values.
  initialValues,
  starterKey,
}) {
  const t = useTranslations("cronJobs");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(createCronjobSchema),
    defaultValues: { ...DEFAULTS, ...initialValues },
  });

  useEffect(() => {
    if (open) form.reset({ ...DEFAULTS, ...initialValues });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialValues]);

  // useWatch, not form.watch: the latter returns a fresh function each render,
  // which the React Compiler can't memoize (it skips the whole component).
  const runAs = useWatch({ control: form.control, name: "run_as" });

  async function onSubmit(values) {
    // The API takes system_user_id XOR username — never both.
    const payload = {
      name: values.name.trim(),
      command: values.command.trim(),
      expression: values.expression.trim(),
      active: values.active,
      ...(values.run_as === OTHER_USER
        ? { username: values.username.trim() }
        : { system_user_id: Number(values.run_as) }),
    };

    try {
      await createCronjob(payload);
      toast.success(t("toast.created"));
      onOpenChange?.(false);
      form.reset(DEFAULTS);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  function handleOpenChange(next) {
    if (!next) form.reset(DEFAULTS);
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={CalendarPlus}
        title={t("create.title")}
        description={t("create.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("create.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("form.name")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("form.namePlaceholder")}
                  autoComplete="off"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="run_as"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("form.runAs")}</FormLabel>
              <Select onValueChange={field.onChange} value={field.value}>
                <FormControl>
                  {/* SelectTrigger defaults to w-fit and h-8. The height lives
                      behind a data-attribute variant, so a plain h-9 loses on
                      specificity — it has to be overridden the same way to
                      line up with Input's h-9. */}
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder={t("form.runAsPlaceholder")} />
                  </SelectTrigger>
                </FormControl>
                <SelectContent position="popper">
                  {systemUsers.map((u) => (
                    <SelectItem key={u.id} value={String(u.id)}>
                      {u.username}
                    </SelectItem>
                  ))}
                  <SelectItem value={OTHER_USER}>{t("form.otherUser")}</SelectItem>
                </SelectContent>
              </Select>
              {/* A picker holding nothing but "Other OS user…" is what you get
                  when this list fails — and it needs the `system_user`
                  permission, which has nothing to do with cronjob, so a 403 is
                  ordinary. Unsaid, it reads as "this server has no accounts"
                  and leaves you to guess that a username can be typed. */}
              {systemUsersFailed ? (
                <FormDescription>{t("form.runAsUnavailable")}</FormDescription>
              ) : null}
              <FormMessage />
            </FormItem>
          )}
        />

        {runAs === OTHER_USER ? (
          <FormField
            control={form.control}
            name="username"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("form.username")}</FormLabel>
                <FormControl>
                  <Input
                    className="font-mono"
                    autoComplete="off"
                    placeholder="www-data"
                    {...field}
                  />
                </FormControl>
                <p className="text-xs text-muted-foreground">
                  {t("form.usernameHint")}
                </p>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : null}

        <CommandField
          form={form}
          presets={commandPresets}
          placeholder={placeholder}
          starterKey={starterKey}
          applications={applications}
        />
        <ScheduleField form={form} presets={schedulePresets} timezone={timezone} />

        <FormField
          control={form.control}
          name="active"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel>{t("form.active")}</FormLabel>
                <p className="text-xs text-muted-foreground">{t("form.activeHint")}</p>
                {/* The only field here without one. `active` is registered and
                    always sent, so a 422 on it would be set inline and render
                    nowhere — silent, exactly like the firewall and system-user
                    cases. Not reachable today; the slot costs nothing. */}
                <FormMessage />
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}

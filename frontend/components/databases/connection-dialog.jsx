import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { CircleCheck, CircleX, Loader2, Plug } from "lucide-react";
import { connectionFormSchema } from "@/lib/schemas/database";
import { updateConnection, testConnection } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { ChoiceField } from "@/components/ui/choice-field";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

/*
 * Default port per engine (MySQL/MariaDB fall back to 3306). The API does not
 * expose `default_port`, so these are the well-known values; a wrong guess is
 * visible and editable.
 */
const DEFAULT_PORT = { mongodb: 27017, postgresql: 5432 };

/*
 * Socket path placeholder per engine; a MySQL path shown for PostgreSQL would
 * be a wrong example.
 */
const SOCKET_HINT = {
  mongodb: "/tmp/mongodb-27017.sock",
  postgresql: "/var/run/postgresql/.s.PGSQL.5432",
};
const DEFAULT_SOCKET = "/var/run/mysqld/mysqld.sock";

/**
 * How the panel itself signs in to an engine; nothing else in the feature
 * works until this connects.
 *
 * The password is write-only: the API never returns it, so an empty field means
 * "leave the stored one alone" rather than "clear it".
 */
export function ConnectionDialog({ engine, connection, open, onOpenChange }) {
  const t = useTranslations("databases.connection");
  const tc = useTranslations("databases");
  const { refreshAndWait } = useRefresh();
  const [testing, setTesting] = useState(false);
  // The last test result, kept on screen rather than in a toast.
  const [result, setResult] = useState(null);

  const defaults = {
    connection_type: connection?.connection_type === "socket" ? "socket" : "tcp",
    host: connection?.host ?? "127.0.0.1",
    port: String(connection?.port ?? DEFAULT_PORT[engine?.engine] ?? 3306),
    socket: connection?.socket ?? "",
    username: connection?.username ?? "root",
    password: "",
  };

  const form = useForm({
    resolver: zodResolver(connectionFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const values = useWatch({ control: form.control });
  const isDirty = form.formState.isDirty;

  async function onSubmit(submitted) {
    const payload = {
      connection_type: submitted.connection_type,
      username: submitted.username,
    };
    if (submitted.connection_type === "tcp") {
      payload.host = submitted.host;
      payload.port = Number(submitted.port);
    } else {
      payload.socket = submitted.socket;
    }
  // Empty keeps the stored password; the API only changes it when a value is sent.
    if (submitted.password) payload.password = submitted.password;

    try {
      const { data } = await updateConnection(engine.engine, payload);
      const saved = data?.[engine.engine];
      await refreshAndWait();
      // Saved and reachable are different outcomes; report both.
      if (saved?.reachable) {
        toast.success(t("savedConnected"));
        onOpenChange?.(false);
      } else {
        setResult(false);
        toast.warning(t("savedNotConnected"));
      }
      form.reset({ ...submitted, password: "" });
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  async function test() {
    setTesting(true);
    try {
      const { data } = await testConnection(engine.engine);
      setResult(Boolean(data?.reachable));
    } catch (error) {
      setResult(false);
      toast.error(apiMessage(error, t("testFailed")));
    } finally {
      setTesting(false);
    }
  }

  function handleOpenChange(next) {
    if (!next) {
      form.reset(defaults);
      setResult(null);
    }
    onOpenChange?.(next);
  }

  const isSubmitting = form.formState.isSubmitting;
  const socket = values.connection_type === "socket";

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Plug}
        title={t("title", { name: tc(`engines.${engine?.engine ?? "mysql"}`) })}
        description={t("subtitle")}
        footer={
          <>
            {/* Tests what is STORED, so it is disabled while the form is dirty. */}
            <ReasonTooltip reason={isDirty ? t("saveFirst") : null}>
              <Button
                type="button"
                variant="outline"
                disabled={isDirty || testing || isSubmitting}
                onClick={test}
              >
                {testing && <Loader2 className="size-4 animate-spin" />}
                {t("test")}
              </Button>
            </ReasonTooltip>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("save")}
            </Button>
          </>
        }
      >
        {result !== null ? <TestResult ok={result} /> : null}

        <FormField
          control={form.control}
          name="connection_type"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("howHint")}>{t("how")}</FormLabel>
              <FormControl>
                <ChoiceField
                  value={field.value}
                  onChange={field.onChange}
                  options={[
                    {
                      value: "tcp",
                      label: t("tcp.label"),
                      hint: t("tcp.hint"),
                    },
                    {
                      value: "socket",
                      label: t("socket.label"),
                      hint: t("socket.hint"),
                    },
                  ]}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        {socket ? (
          <FormField
            control={form.control}
            name="socket"
            render={({ field }) => (
              <FormItem>
                <FormLabel required hint={t("socketPathHint")}>{t("socketPath")}</FormLabel>
                <FormControl>
                  <Input
                    className="font-mono"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder={SOCKET_HINT[engine?.engine] ?? DEFAULT_SOCKET}
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
            <FormField
              control={form.control}
              name="host"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required hint={t("hostHint")}>{t("host")}</FormLabel>
                  <FormControl>
                    <Input
                      className="font-mono"
                      autoComplete="off"
                      spellCheck={false}
                      placeholder="127.0.0.1"
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="port"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required hint={t("portHint")}>{t("port")}</FormLabel>
                  <FormControl>
                    <Input
                      className="font-mono"
                      inputMode="numeric"
                      autoComplete="off"
                      // The engine's own port, not MySQL's.
                      placeholder={String(DEFAULT_PORT[engine?.engine] ?? 3306)}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>
        )}

        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("username")}</FormLabel>
              <FormControl>
                <Input
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="root"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("passwordHint")}>{t("password")}</FormLabel>
              <FormControl>
                <PasswordInput
                  autoComplete="new-password"
                  placeholder={
                    connection?.has_password ? t("passwordSet") : t("passwordNone")
                  }
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}

function TestResult({ ok }) {
  const t = useTranslations("databases.connection");

  return (
    <p
      className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-sm ${
        ok
          ? "border-success/40 bg-success/10 text-success"
          : "border-destructive/40 bg-destructive/5 text-destructive"
      }`}
    >
      {ok ? (
        <CircleCheck className="mt-0.5 size-4 shrink-0" />
      ) : (
        <CircleX className="mt-0.5 size-4 shrink-0" />
      )}
      <span>{ok ? t("reachable") : t("unreachable")}</span>
    </p>
  );
}

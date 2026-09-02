import Link from "next/link";
import { useTranslations } from "next-intl";
import { CircleCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { connectionAddress } from "@/lib/databases/connection-parts";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * What was just created, and how to connect to it.
 *
 * This step exists because "Database created ✓" answers a question nobody
 * asked. People create a database in order to point something at it, so the
 * connection string is the actual result — and the moment it is on screen is
 * the one moment the user is definitely looking.
 *
 * Not a one-time secret reveal, which is what the first version looked like.
 * `DatabaseUserResource` returns the password on every read and the database's
 * own page shows all five values permanently, so urging someone to save them
 * now was pressure over nothing. The dialog says where they live instead.
 *
 * Two tiers, because the five values are not equally useful. The connection
 * string is one line that replaces all of them and is what most people paste
 * into a config file, so it leads; the parts follow for the clients that ask
 * for host and port separately. Before this they were six identical boxes, so
 * the port — which is always 3306 — carried the same weight as the password.
 */
export function CreatedCredentials({ database, open, onOpenChange }) {
  const t = useTranslations("databases");
  const user = database?.users?.[0] ?? null;
  const { host, port } = connectionAddress(user);
  const close = () => onOpenChange?.(false);

  const fields = [
    { key: "host", value: host },
    { key: "port", value: port },
    { key: "database", value: database?.name },
    { key: "username", value: user?.username },
    // Shown in full, unlike the detail page's masked field. The rule is
    // visible at creation, masked at rest: you just deliberately made this
    // credential, and hiding it behind a reveal adds a click at the one moment
    // the value is wanted. Masking it here would also be theatre while the
    // connection string above prints the same password in clear.
    { key: "password", value: user?.password },
  ].filter((field) => field.value);

  // Whether the string carries an escaped copy of the password rather than the
  // literal one — `+` arrives as `%2B`, which reads as a different password
  // from the one in the field below and is worth one sentence.
  //
  // Tested against the two real strings rather than re-deriving PHP's
  // rawurlencode here: this is exactly true whenever the two differ on screen,
  // and never fires when they match.
  const escapedPassword = Boolean(
    user?.password &&
      user?.connection_string &&
      !user.connection_string.includes(user.password),
  );

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      // One size up from the form it replaces. At max-w-lg the connection
      // string broke mid-address ("…@23.45.67.8 / 9:3306/test_db"), which reads
      // as a typo rather than a wrap.
      className="sm:max-w-xl"
      icon={CircleCheck}
      // Green, not the panel's default blue: this dialog reports a finished
      // action rather than asking for one.
      iconTone="success"
      title={t("created.title", { name: database?.name ?? "" })}
      description={user ? t("created.subtitle") : t("created.subtitleNoUser")}
      footer={
        <>
          <Button type="button" variant="outline" onClick={close}>
            {t("created.done")}
          </Button>
          {/* The dialog used to end in a single "Done" — closing it dropped you
              back on the list with nothing done. The database's own page is
              where these values live from now on, so it is the honest next
              step and the answer to "where do I find this again". */}
          {database?.id ? (
            <Button asChild onClick={close}>
              <Link href={`/databases/${database.id}`}>{t("created.open")}</Link>
            </Button>
          ) : null}
        </>
      }
    >
      {/* One bordered block with a divider, not a tinted card followed by a
          loose grid under a floating grey caption. Two containers made the
          parts look like a separate, lesser thing that had been left over; the
          rule says they are the same thing said twice. It is also the shape of
          the connection card on the detail page — the next screen these values
          appear on. */}
      {user?.connection_string || fields.length ? (
        <div className="overflow-hidden rounded-lg border">
          {user?.connection_string ? (
            <div className="space-y-2 border-b bg-muted/40 px-4 py-3.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">
                  {t("created.connectionString")}
                </p>
                {/* Labelled and out of the value's way. Inline, the icon ate
                    ~30px of the line it sits on, which is the width this
                    string most needs. */}
                <CopyButton
                  value={user.connection_string}
                  label={t("created.copyString")}
                  text
                />
              </div>
              <code className="block font-mono text-xs leading-relaxed break-all">
                {user.connection_string}
              </code>
              <p className="text-xs text-muted-foreground">
                {t("created.connectionStringHint")}
              </p>
            </div>
          ) : null}

          {fields.length ? (
            /* Two columns, not the detail card's three. Measured in a real
               render at this dialog's width, not by reasoning about it:

                 3 cols  175px cell  551px tall  name AND password wrap
                 2 cols  239px cell  589px tall  only the name wraps

               Three is denser and 38px shorter, but it breaks the password
               after 19 characters and leaves its last character alone on a
               line — and a password is the one value here read character by
               character. Neither height scrolls, so the wrap is what matters. */
            <div className="grid grid-cols-2 gap-x-4 gap-y-3.5 px-4 py-3.5">
              {fields.map((field) => (
                // The name takes both tracks, same rule as the detail page's
                // card: it is the one long value here, and in a single track it
                // was the only cell that wrapped.
                <div
                  key={field.key}
                  className={cn(
                    "min-w-0",
                    field.key === "database" && "col-span-2",
                  )}
                >
                  {/* The copy button rides with the LABEL, not the value.
                      Inline beside the value it took 26px off every wrapped
                      line, and in a 147px phone cell that pushed the last
                      character of a generated name onto a line of its own —
                      "…215236_xurxc" then "c".

                      Beside the label rather than pushed to the cell's far
                      edge: right-aligned it sat a clear inch from both the word
                      it belongs to and the value it copies. */}
                  <div className="flex items-center gap-0.5">
                    <p className="min-w-0 truncate text-xs text-muted-foreground">
                      {t(`created.${field.key}`)}
                    </p>
                    <CopyButton
                      value={field.value}
                      label={t("credentials.copyField", {
                        field: t(`created.${field.key}`),
                      })}
                      className="size-6"
                    />
                  </div>
                  {/* Wraps rather than truncating: a name cut short still looks
                      like a name, which is worse than two lines. */}
                  <p className="font-mono text-sm break-all">{field.value}</p>
                </div>
              ))}
              {/* A footnote across the whole block, not a note inside the
                  password's cell. In a 154px column it wrapped to four lines,
                  stretched that grid row to match and left a hole beside it —
                  a caption taller than every value it sits among. */}
              {escapedPassword ? (
                <p className="col-span-2 text-xs leading-relaxed text-muted-foreground">
                  {t("created.passwordEscaped")}
                </p>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {user ? null : (
        // A database nobody can sign in to is not finished, and saying so here
        // is cheaper than letting them discover it from a failing app.
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("created.noUserWarning")}
        </p>
      )}
    </FormModal>
  );
}

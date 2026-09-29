"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Eye, KeyRound, Loader2 } from "lucide-react";
import { getContainerSecrets } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";
import { Label } from "@/components/ui/label";

/**
 * The credentials the panel generated when it installed a one-click app.
 *
 * **Hidden until asked for, and fetched only then.** The application payload
 * carries the KEY NAMES and nothing else, so the values are not in every page
 * load, every list response or every cache between the server and the browser.
 * Pressing Reveal is a request, and the server records it.
 *
 * Why this exists at all: Ghost's MySQL password and Strapi-style signing keys
 * were generated per site and then existed only inside a compose file and an
 * encrypted column — correct, and useless to the person who needs them to connect
 * a database client or debug a failing app. It also unblocks the apps whose ADMIN
 * account comes from environment variables: generating a password nobody can read
 * is the same as not having an account.
 *
 * Read-only on purpose. Rotating one means rewriting the compose file and the
 * credential inside the running database, and leaving those two disagreeing is how
 * a site comes back up unable to reach its own data — see the reinstall bug this
 * feature's column was added for. Rotation is a deliberate operation, not a button
 * next to the value.
 */
export function ContainerCredentials({ application }) {
  const t = useTranslations("applications.container.credentials");

  const keys = application.container_secret_keys ?? [];
  const [secrets, setSecrets] = useState(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  if (keys.length === 0) {
    return null;
  }

  async function reveal() {
    setPending(true);
    setError(null);
    try {
      const { data } = await getContainerSecrets(application.id);
      setSecrets(data?.secrets ?? {});
    } catch (requestError) {
      setError(apiMessage(requestError, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="space-y-3">
      <Label>{t("title")}</Label>

      <ul className="divide-y rounded-lg border">
        {keys.map((key) => (
          <li
            key={key}
            className="flex items-center justify-between gap-2 px-3 py-2"
          >
            <span className="min-w-0 truncate font-mono text-xs text-muted-foreground">
              {key}
            </span>
            {secrets ? (
              <span className="flex min-w-0 items-center gap-1.5">
                <code className="min-w-0 truncate font-mono text-xs">
                  {secrets[key]}
                </code>
                <CopyButton value={secrets[key] ?? ""} />
              </span>
            ) : (
              // A fixed number of dots, not the real length — a masked value whose
              // width leaks the password's length is a masked value that helps.
              <span className="font-mono text-xs text-muted-foreground">
                ••••••••••••
              </span>
            )}
          </li>
        ))}
      </ul>

      {secrets ? null : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={pending}
          onClick={reveal}
        >
          {pending ? (
            <Loader2 className="size-4 animate-spin" />
          ) : (
            <Eye className="size-4" />
          )}
          {pending ? t("revealing") : t("reveal")}
        </Button>
      )}

      {secrets ? (
        <Caution icon={KeyRound}>{t("shownWarning")}</Caution>
      ) : (
        <p className="text-xs text-muted-foreground">{t("hint")}</p>
      )}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}

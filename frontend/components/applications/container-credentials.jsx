import { useState } from "react";
import { useTranslations } from "next-intl";
import { Eye, KeyRound, Loader2 } from "lucide-react";
import { getContainerSecrets } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";

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

      {secrets ? <Caution icon={KeyRound}>{t("shownWarning")}</Caution> : null}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}

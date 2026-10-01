import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { GoogleDriveCallback } from "@/components/integrations/storage/google-drive-callback";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("storage.oauth");
  return { title: t("callbackTitle") };
}

/**
 * Google's OAuth redirect target. The browser arrives without a Sanctum token,
 * so only a panel page (which holds the user's credentials) can turn the
 * redirect into an authenticated API request. It also lets a declined consent
 * show a readable message instead of a JSON error.
 *
 * This path is registered with Google: it must not move, and the API route
 * behind it must never be registered as an authorized redirect URI.
 */
export default async function StorageOauthCallbackPage({ searchParams }) {
  const [permissions, params] = await Promise.all([getPermissions(), searchParams]);

  // Writes a credential to the user's Google account; the API enforces this too.
  // Redirects home rather than showing a refusal: this screen is reached from
  // Google, not from the panel, so there is no page to refuse.
  if (!can(permissions, "storage", "manage")) redirect("/");
  return (
    /* Centred one-job card, like the 404; the card owns its own heading. */
    <div className="flex min-h-[60vh] items-center justify-center py-8">
        <GoogleDriveCallback
          code={typeof params?.code === "string" ? params.code : null}
          state={typeof params?.state === "string" ? params.state : null}
          // Sent by Google instead of a code when consent is declined.
          deniedError={typeof params?.error === "string" ? params.error : null}
        />
    </div>
  );
}

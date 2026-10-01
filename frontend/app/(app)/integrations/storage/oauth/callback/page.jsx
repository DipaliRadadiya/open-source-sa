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

// Google's OAuth redirect target: only a panel page holds the token to make the API call.
// This path is registered with Google: never move it, and never register the API route instead.
export default async function StorageOauthCallbackPage({ searchParams }) {
  const [permissions, params] = await Promise.all([getPermissions(), searchParams]);

  // The API enforces this too. Redirects home: reached from Google, there is no page to refuse.
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

import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { PageHeader } from "@/components/ui/page-header";
import { GoogleDriveCallback } from "@/components/integrations/storage/google-drive-callback";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("storage.oauth");
  return { title: t("callbackTitle") };
}

/**
 * Where Google sends the browser back to.
 *
 * This page exists because of what a redirect *is*. Google navigates a browser,
 * and a browser arrives at the panel carrying no Sanctum token — so an API
 * endpoint registered directly with Google could not tell who was connecting,
 * and the OAuth `state` that would normally have said is not backed by a
 * session here. A panel page is the only participant that still holds the
 * user's credentials, so it is the only thing that can turn Google's redirect
 * into an authenticated request.
 *
 * It is also what keeps the failures readable: somebody who clicks "Cancel" on
 * Google's consent screen lands on a panel page that can say so, instead of
 * being shown a JSON error body.
 *
 * This path is the one string registered with Google. It must not move, and the
 * API route behind it must never be registered as an authorized redirect URI.
 */
export default async function StorageOauthCallbackPage({ searchParams }) {
  const [permissions, t, params] = await Promise.all([
    getPermissions(),
    getTranslations("storage.oauth"),
    searchParams,
  ]);

  // Completing a connection writes a credential that can create files in
  // somebody's personal Google account. The API enforces this too; checking
  // here means an unauthorised arrival sees the dashboard rather than a page
  // that fires a request only to be refused.
  if (!can(permissions, "storage", "manage")) redirect("/dashboard");

  return (
    <div className="space-y-6">
      <PageHeader title={t("callbackTitle")} subtitle={t("callbackSubtitle")} />
      <div className="max-w-2xl">
        <GoogleDriveCallback
          code={typeof params?.code === "string" ? params.code : null}
          state={typeof params?.state === "string" ? params.state : null}
          // Google's own refusal, which arrives instead of a code when
          // somebody declines. Passed through so the page can say "nothing was
          // changed" rather than "something went wrong".
          deniedError={typeof params?.error === "string" ? params.error : null}
        />
      </div>
    </div>
  );
}

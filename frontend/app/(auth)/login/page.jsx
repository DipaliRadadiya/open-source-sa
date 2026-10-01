import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

export const dynamic = "force-dynamic";

import Link from "@/components/ui/app-link";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getBasicInfo } from "@/lib/basic-info/get-basic-info";
import { PanelUnavailableCard } from "@/components/sections/panel-unavailable";
import { isPanelUnavailable } from "@/lib/api/unavailable";
import { RateLimitedCard } from "@/components/sections/rate-limited";
import { isRateLimited } from "@/lib/api/rate-limited";
import { RequestFailedCard } from "@/components/sections/request-failed";
import { isRequestFailed, requestFailureProps } from "@/lib/api/request-failed";
import { LoginForm } from "@/components/forms/login-form";
import { SessionExpiredNote } from "@/components/forms/session-expired-note";
import { Logo } from "@/components/logo";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

export default async function LoginPage() {
  // 503/429/request failures are handled HERE, not in error.jsx: production
  // boundaries only receive a digest and cannot tell them from a crash.
  let user, basicInfo, t;
  try {
    [user, basicInfo, t] = await Promise.all([
      getCurrentUser(),
      getBasicInfo(),
      getTranslations("auth"),
    ]);
  } catch (error) {
    if (isRateLimited(error)) return <RateLimitedCard />;
    if (isPanelUnavailable(error)) return <PanelUnavailableCard />;
    if (isRequestFailed(error)) return <RequestFailedCard {...requestFailureProps(error)} />;
    throw error;
  }

  if (user) redirect("/");

  return (
    <Card className="w-full gap-0 shadow-xl shadow-black/5">
      <CardHeader className="flex flex-col items-center gap-2 pb-6 text-center">
        <Logo className="mb-2 h-9 w-auto" />
        <CardTitle className="text-xl">{t("loginTitle")}</CardTitle>
        <CardDescription>{t("loginSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        <SessionExpiredNote />
        <LoginForm />
        {basicInfo.registration_open && (
          <p className="text-center text-sm text-muted-foreground">
            {t("noAccount")}{" "}
            <Link href="/register" className="font-medium text-primary hover:underline">
              {t("registerLink")}
            </Link>
          </p>
        )}
      </CardContent>
    </Card>
  );
}

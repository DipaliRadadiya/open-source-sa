import { redirect } from "next/navigation";

// A bookmarked `?application=` for a site that no longer exists makes the API refuse the
// whole list (422), which would read as "could not be loaded". Drop the filter instead.
// Only once the application list itself loaded, so a failed read never removes it.
export function redirectUnknownApplication(pathname, searchParams, applications, applicationsFailed = false) {
  const wanted = searchParams?.application;
  if (!wanted || applicationsFailed) return;
  if (applications.some((application) => String(application.id) === String(wanted))) return;

  const rest = new URLSearchParams(
    Object.entries(searchParams ?? {}).filter(([key, v]) => key !== "application" && key !== "page" && typeof v === "string"),
  ).toString();
  redirect(rest ? `${pathname}?${rest}` : pathname);
}

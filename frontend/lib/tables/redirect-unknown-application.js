import { redirect } from "next/navigation";

// An unknown ?application= makes the API refuse the whole list, so drop the filter.
export function redirectUnknownApplication(pathname, searchParams, applications, applicationsFailed = false) {
  const wanted = searchParams?.application;
  if (!wanted || applicationsFailed) return;
  if (applications.some((application) => String(application.id) === String(wanted))) return;

  const rest = new URLSearchParams(
    Object.entries(searchParams ?? {}).filter(([key, v]) => key !== "application" && key !== "page" && typeof v === "string"),
  ).toString();
  redirect(rest ? `${pathname}?${rest}` : pathname);
}

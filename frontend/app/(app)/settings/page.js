import { redirect } from "next/navigation";

// Settings has no landing screen of its own — the first section is the page.
export default function SettingsPage() {
  redirect("/settings/server");
}

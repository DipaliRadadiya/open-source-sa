import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";
import { errorTarget } from "@/lib/api/error-target";

/**
 * Show a 422 where the user can act on it.
 *
 * Field errors go next to their field, which is the whole point of a 422. But an
 * error can name a field the request never contained — creating a cron job for a
 * managed system user sends `system_user_id` and the API answers with an error
 * on `username`, the *other* branch of that control. `setError` then stores a
 * message against an input that is not on screen, and the old code returned
 * without a toast: the user pressed Create, nothing appeared anywhere, and the
 * only evidence was in the network tab.
 *
 * So anything the request did not send is announced instead. The submitted body
 * is the honest test — not the form's field list, which still holds the keys of
 * branches the user is not using.
 */
export function handleValidationError(error, form, { formError = false } = {}) {
  const errors = error.response?.data?.errors;

  if (errors && form) {
    let sent = {};
    try {
      // Axios keeps the serialised request body. A non-JSON body (an upload)
      // simply yields nothing here, and everything falls through to the toast.
      sent = JSON.parse(error.config?.data ?? "{}");
    } catch {
      sent = {};
    }

    // The form's own field names. `setError` will happily store a message
    // against a name nothing renders, which is the other way an error goes
    // missing: the firewall dialog sends `port_from` but its input is called
    // `ports`, so "a rule with these settings already exists" landed in form
    // state and appeared nowhere on screen.
    const fields = form.getValues() ?? {};

    const orphaned = [];
    Object.entries(errors).forEach(([field, messages]) => {
      // Which name to set it on, or null for "nothing here would render it".
      // Pure and tested in lib/api/error-target.js — the three ways a message
      // can vanish are all decided there.
      const target = errorTarget(field, fields, sent);
      if (target) form.setError(target, { message: messages[0] });
      else orphaned.push(messages[0]);
    });

    if (orphaned.length === 0) return;
    // One message, the first: a 422 rarely carries two unrelated surprises, and
    // stacking them buries the one the user can act on.
    //
    // On the form when the form can show it, so it stays put while the dialog
    // does — a toast slides away while the user is still reading the fields it
    // was about. `root.server` is react-hook-form's own slot for an error that
    // belongs to the submission rather than to any one input; forms that do not
    // render it fall through to the toast, which is what every form did before.
    if (formError) {
      form.setError("root.server", { message: orphaned[0] });
      return;
    }
    toast.error(orphaned[0]);
    return;
  }

  const message = apiMessage(error, "Something went wrong");
  toast.error(message);
}

import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";

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
export function handleValidationError(error, form) {
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

    const orphaned = [];
    Object.entries(errors).forEach(([field, messages]) => {
      // Nested keys arrive dotted (`settings.token`); the root is what was sent.
      const root = field.split(".")[0];
      if (Object.prototype.hasOwnProperty.call(sent, root)) {
        form.setError(field, { message: messages[0] });
      } else {
        orphaned.push(messages[0]);
      }
    });

    if (orphaned.length === 0) return;
    // One toast, first message: a 422 rarely carries two unrelated surprises,
    // and stacking them buries the one the user can act on.
    toast.error(orphaned[0]);
    return;
  }

  const message = apiMessage(error, "Something went wrong");
  toast.error(message);
}

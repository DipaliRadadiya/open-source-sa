import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";
import { errorTarget } from "@/lib/api/error-target";
import { genericErrorMessage } from "@/lib/api/generic-error";

/**
 * Show a 422 where the user can act on it.
 *
 * Field errors go next to their field. Errors on fields that were not sent or
 * have no control on screen (see `errorTarget`) would be stored invisibly, so
 * they are shown on the form (`formError`) or as a toast instead.
 */
export function handleValidationError(error, form, { formError = false, unrendered = [] } = {}) {
  const errors = error.response?.data?.errors;

  if (errors && form) {
    let sent = {};
    try {
      // Axios keeps the serialised body; a non-JSON body (upload) yields nothing.
      sent = JSON.parse(error.config?.data ?? "{}");
    } catch {
      sent = {};
    }

    const fields = form.getValues() ?? {};

    const orphaned = [];
    Object.entries(errors).forEach(([field, messages]) => {
      const target = errorTarget(field, fields, sent, unrendered);
      if (target) form.setError(target, { message: messages[0] });
      else orphaned.push(messages[0]);
    });

    if (orphaned.length === 0) return;
    // Only the first orphaned message. `root.server` is react-hook-form's slot
    // for submission-level errors; forms that do not render it use the toast.
    if (formError) {
      form.setError("root.server", { message: orphaned[0] });
      return;
    }
    toast.error(orphaned[0]);
    return;
  }

  // Translated, handed over by the shell — see lib/api/generic-error.js.
  const message = apiMessage(error, genericErrorMessage());
  toast.error(message);
}

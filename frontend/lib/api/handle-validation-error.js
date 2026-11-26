import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";
import { errorTarget } from "@/lib/api/error-target";
import { genericErrorMessage } from "@/lib/api/generic-error";

export function handleValidationError(error, form, { formError = false, unrendered = [], fallback } = {}) {
  const errors = error.response?.data?.errors;

  if (errors && Object.keys(errors).length > 0 && form) {
    let sent = {};
    try {
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
    // root.server is react-hook-form's slot for form-level errors.
    if (formError) {
      form.setError("root.server", { message: orphaned[0] });
      return;
    }
    toast.error(orphaned[0]);
    return;
  }

  const message = apiMessage(error, fallback ?? genericErrorMessage());
  toast.error(message);
}

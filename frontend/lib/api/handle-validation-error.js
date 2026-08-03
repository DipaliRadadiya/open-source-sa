import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";

export function handleValidationError(error, form) {
  const errors = error.response?.data?.errors;

  if (errors && form) {
    Object.entries(errors).forEach(([field, messages]) => {
      form.setError(field, { message: messages[0] });
    });
    return;
  }

  const message = apiMessage(error, "Something went wrong");
  toast.error(message);
}

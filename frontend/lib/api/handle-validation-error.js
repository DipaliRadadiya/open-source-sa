import { toast } from "sonner";

export function handleValidationError(error, form) {
  const errors = error.response?.data?.errors;

  if (errors && form) {
    Object.entries(errors).forEach(([field, messages]) => {
      form.setError(field, { message: messages[0] });
    });
    return;
  }

  const message = error.response?.data?.message || "Something went wrong";
  toast.error(message);
}

import { z } from "zod";

export const loginSchema = z.object({
  username: z.string().min(1, "required_username"),
  password: z.string().min(1, "required_password"),
});

export const registerSchema = z
  .object({
    name: z.string().min(1, "required_name"),
    username: z
      .string()
      .min(1, "required_username")
      .regex(/^[a-zA-Z0-9_-]+$/, "usernameChars"),
    password: z
      .string()
      .min(10, "min10")
      .regex(/[a-z]/, "lowercase")
      .regex(/[A-Z]/, "uppercase")
      .regex(/[0-9]/, "number"),
    password_confirmation: z.string().min(1, "confirmPassword"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "passwordsMismatch",
    path: ["password_confirmation"],
  });

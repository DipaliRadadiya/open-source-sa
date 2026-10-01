import * as React from "react"
import { Slot } from "radix-ui"
import {
  Controller,
  FormProvider,
  useFormContext,
  useFormState,
} from "react-hook-form"
import { useTranslations } from "next-intl"

import { cn } from "@/lib/utils"
import { Label } from "@/components/ui/label"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"

const Form = FormProvider

const FormFieldContext = React.createContext({})

function FormField({ ...props }) {
  return (
    <FormFieldContext.Provider value={{ name: props.name }}>
      <Controller {...props} />
    </FormFieldContext.Provider>
  )
}

function useFormField() {
  const fieldContext = React.useContext(FormFieldContext)
  const itemContext = React.useContext(FormItemContext)
  const { getFieldState } = useFormContext()
  const formState = useFormState({ name: fieldContext.name })
  const fieldState = getFieldState(fieldContext.name, formState)

  if (!fieldContext) {
    throw new Error("useFormField should be used within <FormField>")
  }

  const { id } = itemContext

  return {
    id,
    name: fieldContext.name,
    formItemId: `${id}-form-item`,
    formDescriptionId: `${id}-form-item-description`,
    formMessageId: `${id}-form-item-message`,
    ...fieldState,
  }
}

// Carries the label so FormMessage can say "The Name field is required.".
// Derived from the direct FormLabel child; refs cannot be written during render.
const FormItemContext = React.createContext({ label: null })

function formLabelText(children) {
  for (const child of React.Children.toArray(children)) {
    if (!React.isValidElement(child)) continue
    if (child.type === FormLabel && typeof child.props.children === "string") {
      return child.props.children
    }
    if (child.type === React.Fragment) {
      const nested = formLabelText(child.props.children)
      if (nested) return nested
    }
  }
  return null
}

function FormItem({ className, children, ...props }) {
  const id = React.useId()
  const label = formLabelText(children)

  return (
    <FormItemContext.Provider value={{ id, label }}>
      {/* content-start keeps inputs aligned when a grid neighbour is taller. */}
      <div data-slot="form-item" className={cn("grid content-start gap-2", className)} {...props}>
        {children}
      </div>
    </FormItemContext.Provider>
  )
}

// Exported for forms that use a plain <Label> outside react-hook-form.
// No tab stop on purpose: Radix Dialog would auto-focus it and open the
// tooltip when a modal first renders (radix-ui#1949).
function RequiredMark() {
  const t = useTranslations("common")
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="ml-0.5 text-destructive">
          {/* The tooltip is unreachable without hover, so the word is in the label for screen readers. */}
          <span aria-hidden="true">*</span>
          <span className="sr-only">{t("required")}</span>
        </span>
      </TooltipTrigger>
      <TooltipContent>{t("required")}</TooltipContent>
    </Tooltip>
  )
}

/**
 * `required` adds the shared asterisk. `hint` is forwarded to Label, which owns
 * the "?" explanation and renders it after the asterisk. Use `hint` only on
 * technical fields.
 */
function FormLabel({ className, required, hint, children, ...props }) {
  const { error, formItemId } = useFormField()

  return (
    <Label
      data-slot="form-label"
      data-error={!!error}
      className={cn("data-[error=true]:text-destructive", className)}
      htmlFor={formItemId}
      hint={hint}
      {...props}
    >
      {children}
      {required ? <RequiredMark /> : null}
    </Label>
  )
}

function FormControl({ ...props }) {
  const { error, formItemId, formDescriptionId, formMessageId } = useFormField()

  return (
    <Slot.Root
      data-slot="form-control"
      id={formItemId}
      aria-describedby={
        !error ? `${formDescriptionId}` : `${formDescriptionId} ${formMessageId}`
      }
      aria-invalid={!!error}
      {...props}
    />
  )
}

function FormDescription({ className, ...props }) {
  const { formDescriptionId } = useFormField()

  return (
    <p
      data-slot="form-description"
      id={formDescriptionId}
      // Smaller than the label; muted-foreground is already near the contrast floor.
      className={cn("text-muted-foreground text-xs leading-relaxed font-normal", className)}
      {...props}
    />
  )
}

function FormMessage({ className, field, ...props }) {
  const { error, formMessageId } = useFormField()
  const tv = useTranslations("validation")
  const tc = useTranslations("common")
  const { label: detectedLabel } = React.useContext(FormItemContext)

  // Zod messages are validation keys and get translated; anything else (e.g.
  // an already localised backend error) renders as-is. Explicit children win,
  // for forms whose keys live in another namespace.
  const raw = props.children ?? (error ? String(error?.message ?? "") : null)
  // "requiredField" means the field was left empty. Priority: explicit `field`
  // prop > auto-detected label from FormLabel > generic message.
  const label = field ?? detectedLabel ?? null
  const body =
    typeof raw === "string" && raw
      ? raw === "requiredField"
        ? label
          ? tc("requiredFieldNamed", { field: label })
          : tc("requiredField")
        : tv.has(raw)
          ? tv(raw)
          : raw
      : raw

  if (!body) {
    return null
  }

  return (
    <p
      data-slot="form-message"
      id={formMessageId}
      className={cn("text-destructive text-sm", className)}
      {...props}
    >
      {body}
    </p>
  )
}

export {
  Form,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
  FormField,
  RequiredMark,
  useFormField,
}

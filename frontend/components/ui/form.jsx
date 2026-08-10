"use client"

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

const FormItemContext = React.createContext({})

function FormItem({ className, ...props }) {
  const id = React.useId()

  return (
    <FormItemContext.Provider value={{ id }}>
      {/* content-start: without it a shorter field stretched by a taller
          neighbour in the same grid row spreads the leftover height into its
          gap, dropping its input below the neighbour's. */}
      <div data-slot="form-item" className={cn("grid content-start gap-2", className)} {...props} />
    </FormItemContext.Provider>
  )
}

// The asterisk-tooltip itself, exported standalone for the handful of forms
// that label a field with a plain <Label> instead of the FormField/FormLabel
// trio (no react-hook-form field context to read `required` off of there) —
// one implementation either way, not a second copy.
// The asterisk is purely decorative — hover shows the tooltip, no tab stop needed.
// Removing tabIndex={0} also prevents Radix Dialog from auto-focusing it
// and opening the tooltip when a modal first renders (RAD-2315 / radix-ui#1949).
function RequiredMark() {
  const t = useTranslations("common")
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          className="ml-0.5 text-destructive"
        >
          *
        </span>
      </TooltipTrigger>
      <TooltipContent>{t("required")}</TooltipContent>
    </Tooltip>
  )
}

// `required` marks a mandatory field with the same asterisk-tooltip
// everywhere, instead of every form re-implementing (or forgetting) it.
function FormLabel({ className, required, children, ...props }) {
  const { error, formItemId } = useFormField()

  return (
    <Label
      data-slot="form-label"
      data-error={!!error}
      className={cn("data-[error=true]:text-destructive", className)}
      htmlFor={formItemId}
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
      // Smaller and lighter than the label above it. Both were text-sm, so a
      // hint carried the same visual weight as the thing it was explaining and
      // the form read as a wall of equal-sized lines. Colour stays at
      // muted-foreground rather than going fainter: this is still body text
      // someone has to read, and the token is already near the contrast floor.
      className={cn("text-muted-foreground text-xs leading-relaxed font-normal", className)}
      {...props}
    />
  )
}

function FormMessage({ className, ...props }) {
  const { error, formMessageId } = useFormField()
  const t = useTranslations("validation")
  // Zod messages are validation keys (e.g. "min10") — translate them. Anything
  // that isn't a known key (e.g. a backend error, already localized) renders
  // as-is.
  // Explicit children win over the field's own error. Settings schemas emit
  // keys that live under `settings.validation`, not this namespace, so the
  // form translates them itself and passes the sentence in — before this, the
  // raw error always shadowed it and the user read "invalidHostname".
  const raw = props.children ?? (error ? String(error?.message ?? "") : null)
  const body = typeof raw === "string" && raw && t.has(raw) ? t(raw) : raw

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

'use client';

import { useRef, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  CreditCustomerForm,
  type CreditResult,
} from '@/components/credit/credit-customer-form';

/**
 * The credit form AS A DIALOG (owner, 2026-08-25): the dashboard's quick
 * action opens the counter screen over the top of the figures instead of
 * navigating away from them.
 *
 * This file is HOST CHROME ONLY — a title, a scroll box, a way out, and a
 * question before that way out throws typing away. It contains no field, no
 * rule and no request: the sale is keyed into `CreditCustomerForm`, the same
 * component the /credit route renders, so the dialog and the page cannot
 * drift into telling a merchant different things about the same sale.
 *
 * CLOSING IS DELIBERATELY HARD. A stray click on the page behind, or a
 * reflex Escape, costs a cashier a customer code, an invoice number, the
 * amounts and any split rows — with a customer standing there. So:
 *
 *  - an outside click is refused (`onInteractOutside`), and
 *  - Escape is refused (`onEscapeKeyDown`),
 *
 * both at the primitive, which is what keeps the overlay a real overlay
 * (focus trap, scroll lock, `aria-modal`) instead of a div pretending. What
 * remains is two EXPLICIT exits, Cancel and the X, both reachable by
 * keyboard — nothing is trapped, it just cannot happen by accident. Either
 * one asks first when there is something typed to lose.
 *
 * AND BOTH ARE SHUT WHILE A CREDIT IS IN FLIGHT. Everything the form says
 * about a recorded sale — below-minimum, on hold, backdated-and-final — is
 * said in the callback on that one request. Closing over the top of it
 * unmounts the form and takes that callback with it while the server goes
 * on recording the sale, which would leave a merchant with a changed
 * balance, no confirmation, and a discard question that had just promised
 * them nothing was recorded. The window is one request long.
 */
export function CreditCustomerDialog({
  open,
  onOpenChange,
  onCredited,
  returnFocusTo,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * A sale was recorded and the dialog has closed itself. The host says
   * what happened next — the dashboard shows the same result card the
   * /credit route shows. The figures behind it need no help: the credit
   * mutation invalidates outstanding and transactions itself, so the
   * dashboard's own queries refetch as this closes.
   */
  onCredited: (result: CreditResult) => void;
  /**
   * The control that opened this. The dialog is opened by state rather than
   * a `DialogTrigger`, so Radix has no trigger to hand focus back to and
   * would drop it on `<body>` — a keyboard or screen-reader user would be
   * returned to the top of the dashboard every time this closes.
   */
  returnFocusTo?: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  // Reported by the form, because only the form can see its own fields.
  const [dirty, setDirty] = useState(false);
  // Also reported by the form: a credit is with the server right now.
  const [busy, setBusy] = useState(false);
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);
  /**
   * Where the caret was when the discard question was raised — the Cancel
   * button or the X. "Keep editing" puts it back there, which is what a
   * focus scope would do for us if Radix were not already preventing its
   * own restore (it focuses a `DialogTrigger`, and there isn't one).
   */
  const restoreFocusRef = useRef<HTMLElement | null>(null);

  const close = () => {
    // Discarding closes BOTH dialogs at once; the credit dialog's own
    // return-focus is the right one then, so this must not compete.
    restoreFocusRef.current = null;
    setConfirmingDiscard(false);
    // The body is unmounted on close, so the next open starts clean; this
    // clears the host's copy of the answers with it.
    setDirty(false);
    setBusy(false);
    onOpenChange(false);
  };

  /**
   * Every way out lands here — Cancel, the X, and any future one. With
   * outside-click and Escape refused above, those are the only ways out,
   * which is exactly why the question can live in one place.
   *
   * ONCE A CREDIT IS SENT, THERE IS NO WAY OUT BUT THE SERVER'S ANSWER.
   * Closing now would unmount the form and lose the confirmation for a sale
   * the server records regardless — the merchant would be told nothing, and
   * the discard question would have told them nothing was recorded. It is a
   * moment long, and it ends by itself in the result card.
   */
  const requestClose = () => {
    if (busy) return;
    if (dirty) {
      restoreFocusRef.current = document.activeElement as HTMLElement | null;
      setConfirmingDiscard(true);
      return;
    }
    close();
  };

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (next) {
            setDirty(false);
            setBusy(false);
            onOpenChange(true);
            return;
          }
          // The X is a DialogClose, so it arrives here rather than at a
          // handler of ours — and gets the same question as Cancel.
          requestClose();
        }}
      >
        <DialogContent
          // Tall form, short phone: the dialog is capped to the viewport and
          // the BODY scrolls, so the entry never runs off the bottom and the
          // dashboard behind never scrolls in its place.
          className="max-w-2xl max-h-[calc(100vh-2rem)]"
          // While the sale is with the server the X goes with Cancel: the
          // only exit is the answer. (`requestClose` refuses it anyway —
          // this is so the dialog does not offer a control that does
          // nothing.)
          showCloseButton={!busy}
          onInteractOutside={(event) => event.preventDefault()}
          onEscapeKeyDown={(event) => event.preventDefault()}
          // Hand focus back to whatever opened this. Radix's own default
          // focuses a `DialogTrigger`, and there isn't one, so without this
          // the caret lands on <body> and a keyboard user restarts at the
          // top of the dashboard.
          onCloseAutoFocus={(event) => {
            event.preventDefault();
            returnFocusTo?.current?.focus();
          }}
        >
          <DialogHeader>
            <DialogTitle>{t('credit.title')}</DialogTitle>
            <DialogDescription>{t('credit.subtitle')}</DialogDescription>
          </DialogHeader>
          <DialogBody className="flex min-h-0 flex-col overflow-y-auto">
            {/* Radix unmounts this on close, so a cancelled entry is really
                gone and the next open is a fresh form — no reset logic. */}
            <CreditCustomerForm
              variant="narrow"
              onDirtyChange={setDirty}
              onBusyChange={setBusy}
              onCancel={requestClose}
              onDone={(result) => {
                setConfirmingDiscard(false);
                setDirty(false);
                // The request is answered — the exits open again, and the
                // dialog uses one immediately.
                setBusy(false);
                onOpenChange(false);
                onCredited(result);
              }}
            />
          </DialogBody>
        </DialogContent>
      </Dialog>

      {/* Asked ON TOP of the dialog, never instead of it: the entry is still
          there behind the question, and "Keep editing" returns to it. */}
      <AlertDialog
        open={confirmingDiscard}
        onOpenChange={(next) => {
          if (!next) setConfirmingDiscard(false);
        }}
      >
        <AlertDialogContent
          // "Keep editing" must land back on the control that asked, inside
          // the credit form that is still open behind this — not on <body>,
          // which is where Radix's trigger-less default leaves it.
          onCloseAutoFocus={(event) => {
            event.preventDefault();
            restoreFocusRef.current?.focus();
          }}
        >
          <AlertDialogHeader>
            <AlertDialogTitle>{t('credit.discardTitle')}</AlertDialogTitle>
            <AlertDialogDescription>
              {t('credit.discardBody')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('credit.discardKeep')}</AlertDialogCancel>
            <AlertDialogAction onClick={close}>
              {t('credit.discardConfirm')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

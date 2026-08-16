'use client';

import { useState } from 'react';
import { type BankSlug, type MerchantBankAccount } from '@manfaa/api-client';
import { CircleCheck, Info, LoaderCircle } from 'lucide-react';
import { apiErrorMessage, useUpdateBankAccount } from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { BankLabel, BankSelect } from '@/components/app/bank-select';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';

/**
 * The merchant's own bank identity — used to MATCH the transfers they send
 * us against their settlements. Money flows merchant → Manfaa only; this is
 * never a payout destination. There is no read endpoint, so the form is
 * write-only: all three fields are saved together as one identity.
 */
export default function BankAccountSettingsPage() {
  const updateBankAccount = useUpdateBankAccount();

  const [bankName, setBankName] = useState<BankSlug | ''>('');
  const [bankAccount, setBankAccount] = useState('');
  const [bankAccountName, setBankAccountName] = useState('');
  const [saved, setSaved] = useState<MerchantBankAccount | null>(null);

  const canSave =
    bankName !== '' &&
    bankAccount.trim() !== '' &&
    bankAccountName.trim() !== '' &&
    !updateBankAccount.isPending;

  const save = () => {
    // canSave already refuses the empty case; this narrows for the compiler
    // rather than casting, so a future edit that loosens canSave fails here
    // instead of posting an empty bank.
    if (bankName === '') return;

    updateBankAccount.mutate(
      {
        bank_name: bankName,
        bank_account: bankAccount.trim(),
        bank_account_name: bankAccountName.trim(),
      },
      {
        onSuccess: (response) => {
          setSaved(response.data);
          setBankName('');
          setBankAccount('');
          setBankAccountName('');
          toast.success('Bank account saved');
        },
        onError: (error) =>
          toast.error(
            apiErrorMessage(error, 'Could not save the bank account.'),
          ),
      },
    );
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Bank account</ToolbarPageTitle>
          <ToolbarDescription>
            The account you send settlement transfers from
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <div className="max-w-xl flex flex-col gap-5 pb-7.5">
        <Alert variant="info" appearance="light">
          <AlertIcon>
            <Info />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>For payment matching only</AlertTitle>
            <AlertDescription>
              We use these details to recognise your incoming settlement
              transfers. Manfaa does not send payments to this account.
            </AlertDescription>
          </AlertContent>
        </Alert>

        {saved && (
          <Alert variant="success" appearance="light">
            <AlertIcon>
              <CircleCheck />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>Bank account on file</AlertTitle>
              <AlertDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <BankLabel bank={saved.bank_name} />
                <span>·</span>
                <span>{saved.bank_account}</span>
                <span>·</span>
                <span>{saved.bank_account_name}</span>
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        <Card>
          <CardHeader>
            <CardTitle>Update bank account</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="bank-name">Bank</Label>
              <BankSelect
                id="bank-name"
                value={bankName}
                onChange={setBankName}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="bank-account">Account number</Label>
              <Input
                id="bank-account"
                value={bankAccount}
                maxLength={64}
                onChange={(event) => setBankAccount(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="bank-account-name">Account name</Label>
              <Input
                id="bank-account-name"
                value={bankAccountName}
                maxLength={255}
                placeholder="Exactly as it appears on the account"
                onChange={(event) => setBankAccountName(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                All three fields are saved together — the identity is one
                unit, and a partial update would mismatch every payment. For
                security, the current details are not displayed here; saving
                replaces them.
              </p>
            </div>
          </CardContent>
          <CardFooter className="justify-end">
            <Button disabled={!canSave} onClick={save}>
              {updateBankAccount.isPending && (
                <LoaderCircle className="animate-spin" />
              )}
              Save bank account
            </Button>
          </CardFooter>
        </Card>
      </div>
    </div>
  );
}

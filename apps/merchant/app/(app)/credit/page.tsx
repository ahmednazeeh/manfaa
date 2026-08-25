'use client';

import { useTranslation } from 'react-i18next';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { CreditCustomerForm } from '@/components/credit/credit-customer-form';

/**
 * The counter screen's ROUTE (§10, original-spec §8 manual credit path):
 * the page title, the description and the container — the chrome a screen
 * has and a dialog does not.
 *
 * Everything the screen actually DOES lives in CreditCustomerForm: the
 * customer lookup, the split editor, the per-sale rate override, the
 * backdating confirmation, the cost quote, the request and its refusals.
 * That component is the single implementation, so any other host of the
 * credit form records a sale by exactly the same rules and says exactly
 * the same words — including the two words of chrome above, which come
 * from `credit.title` / `credit.subtitle` so the dashboard's dialog names
 * this screen exactly as this screen names itself.
 */
export default function CreditPage() {
  const { t } = useTranslation();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('credit.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('credit.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <CreditCustomerForm variant="wide" />
    </div>
  );
}

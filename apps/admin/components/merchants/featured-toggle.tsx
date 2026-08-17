'use client';

import {
  setAdminMerchantFeatured,
  type MerchantStanding,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Star } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * The storefront "featured" shelf flag as a one-click star. No confirmation
 * dialog on purpose: the flag is editorial, money-free and instantly
 * reversible by the same click. The server busts the discovery cache on a
 * real change, so the public shelf follows before the row even refetches.
 */
export function FeaturedToggle({ merchant }: { merchant: MerchantStanding }) {
  const queryClient = useQueryClient();

  const toggle = useMutation({
    mutationFn: () => setAdminMerchantFeatured(merchant.id, !merchant.featured),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'merchants'] });
      toast.success(
        response.data.featured
          ? `${merchant.name} is now featured on the storefront.`
          : `${merchant.name} removed from the featured shelf.`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          disabled={toggle.isPending}
          aria-pressed={merchant.featured}
          aria-label={
            merchant.featured
              ? `Remove ${merchant.name} from the featured shelf`
              : `Feature ${merchant.name} on the storefront`
          }
          onClick={() => toggle.mutate()}
        >
          <Star
            className={cn(
              merchant.featured
                ? 'fill-amber-400 stroke-amber-500'
                : 'stroke-muted-foreground',
            )}
          />
        </Button>
      </TooltipTrigger>
      <TooltipContent>
        {merchant.featured
          ? 'Featured on the storefront — click to remove'
          : 'Feature on the storefront'}
      </TooltipContent>
    </Tooltip>
  );
}

'use client';

import { BrandMark } from '@manfaa/ui';

export function ScreenLoader() {
  return (
    <div className="flex flex-col items-center gap-2 justify-center fixed inset-0 z-50 transition-opacity duration-700 ease-in-out">
      <BrandMark
        shape="square"
        className="h-10 w-auto object-contain"
        alt="Manfaa"
      />
      <div className="text-muted-foreground font-medium text-sm">
        Loading...
      </div>
    </div>
  );
}

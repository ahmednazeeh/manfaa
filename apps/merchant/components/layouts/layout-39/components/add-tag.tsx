import { toast } from 'sonner';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CircleCheck } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface AddTagDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreate?: (data: { name: string; color: string; }) => void;
}

export function AddTagDialog({ open, onOpenChange, onCreate }: AddTagDialogProps) {
  const [labelName, setLabelName] = useState('');
  const [selectedColor, setSelectedColor] = useState<string>('');

  const handleCreate = () => {
    const name = labelName.trim();
    if (!selectedColor) {
      return;
    }
    onCreate?.({ name, color: selectedColor });

    toast.custom(
      (t) => (
        <Alert variant="mono" icon="success" onClose={() => toast.dismiss(t)}>
          <AlertIcon>
            <CircleCheck />
          </AlertIcon>
          <AlertTitle>
            Added "{name}" tag
          </AlertTitle>
        </Alert>
      ),
      { duration: 2500 }
    );

    setLabelName('');
    setSelectedColor('');
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md"
        onKeyDown={(e) => {
          if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && labelName.trim() && selectedColor) {
            e.preventDefault();
            handleCreate();
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>Add New Tag</DialogTitle>
        </DialogHeader>
          <div className="space-y-4">
            <Input
              placeholder="Enter tag name"
              value={labelName}
              onChange={(e) => setLabelName(e.target.value)}
            />
            <div className="flex items-center gap-1 lg:gap-3 flex-wrap">
              {['#ef4444', '#22c55e', '#eab308', '#3b82f6', '#8b5cf6'].map((c) => (
                <button
                  key={c}
                  type="button"
                  onClick={() => setSelectedColor(c)}
                  className={cn(
                    'size-6 rounded-full',
                    'focus:outline-none focus:ring-2 focus:ring-primary/30 shrink-0',
                    selectedColor === c ? 'ring-2 ring-primary/30' : ''
                  )}
                  style={{ backgroundColor: c }}
                />
              ))}
            </div>
          </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button variant="primary" onClick={handleCreate} disabled={!labelName.trim() || !selectedColor}>Add Tag</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

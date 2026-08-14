import { useState } from 'react';
import { Plus } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export function NewTask({ isCollapsed }: { isCollapsed: boolean }) {
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [newListName, setNewListName] = useState('');

  return (
    <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
      {isCollapsed ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <DialogTrigger asChild>
              <Button
                className={cn(
                  'mx-auto w-full bg-linear-to-r from-primary to-blue-600 hover:opacity-90 dark:hover:opacity-85 text-white shadow-lg text-sm transition-opacity',
                  'dark:from-blue-950 dark:to-blue-800',
                )}
                size="icon"
              >
                <Plus className="size-4" />
              </Button>
            </DialogTrigger>
          </TooltipTrigger>
          <TooltipContent side="right">New Task</TooltipContent>
        </Tooltip>
      ) : (
        <DialogTrigger asChild>
          <Button
            className={cn(
              'mx-auto w-full bg-linear-to-r from-primary to-blue-600 hover:opacity-90 dark:hover:opacity-85 text-white shadow-lg text-sm transition-opacity',
              'dark:from-blue-950 dark:to-blue-800',
            )} 
            size="md"
          >
            <Plus className="size-4" />
            <span className="font-semibold">New Task</span>
          </Button>
        </DialogTrigger>
      )}

      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New Task</DialogTitle>
        </DialogHeader>

        <Input
          value={newListName}
          onChange={(event) => setNewListName(event.target.value)}
          placeholder="Add a new task"
          autoFocus
        />

        <DialogFooter>
          <Button
            variant="primary"
            disabled={!newListName.trim()}
            onClick={() => {
              setIsCreateDialogOpen(false);
              setNewListName('');
            }}
          >
            Continue
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

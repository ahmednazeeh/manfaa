import { useState } from 'react';
import { Plus } from 'lucide-react';
import { AddTagDialog } from './add-tag';
import { Button } from '@/components/ui/button';
import { BadgeDot } from '@/components/ui/badge';

interface Tag {
  id: string;
  name: string;
  color: string;
}

const defaultTags: Tag[] = [
  { id: '1', name: 'Work', color: '#ef4444' },
  { id: '2', name: 'Personal', color: '#22c55e' },
  { id: '3', name: 'Team', color: '#eab308' },
  { id: '4', name: 'Goals', color: '#3b82f6' },
];

export function SidebarTags() {
  const [tags, setTags] = useState<Tag[]>(defaultTags);
  const [isDialogOpen, setIsDialogOpen] = useState(false);

  const handleCreateTag = (data: { name: string; color: string; }) => {
    const newTag: Tag = {
      id: Date.now().toString(),
      name: data.name,
      color: data.color,
    };
    setTags([...tags, newTag]);
  };

  return (
    <>
      <h4 className="text-xs px-2 mb-2 text-muted-foreground">Tags</h4>
      <div className="flex flex-wrap gap-2">
        {tags.map((tag) => (
          <Button
            key={tag.id}
            size="sm"
            variant="outline"
          >
            <BadgeDot style={{ backgroundColor: tag.color }} />{tag.name}
          </Button>
        ))}
        
        <Button variant="outline" size="sm" onClick={() => setIsDialogOpen(true)}>
          <Plus className="size-4" />Add tag
        </Button>
      </div>

      <AddTagDialog
        open={isDialogOpen}
        onOpenChange={setIsDialogOpen}
        onCreate={handleCreateTag}
      />
    </>
  );
}

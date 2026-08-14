import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { 
  Settings, 
  Star, 
  Trash2, 
  History, 
  FileText, 
} from "lucide-react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { SectionHeader } from "./section-header";

interface QuickActionsProps {
  isCollapsed?: boolean;
}

export function QuickActions({ isCollapsed = false }: QuickActionsProps) {
  if (isCollapsed) {
    return (
      <div className="space-y-2 flex flex-col items-center">
        <Tooltip>
          <TooltipTrigger asChild>
            <Button mode="icon" variant="ghost">
              <Star />
            </Button>
          </TooltipTrigger>
          <TooltipContent side="right">Favorites</TooltipContent>
        </Tooltip>
        
        <Tooltip>
          <TooltipTrigger asChild>
            <Button mode="icon" variant="ghost">
              <Trash2 />
            </Button>
          </TooltipTrigger>
          <TooltipContent side="right">Clear History</TooltipContent>
        </Tooltip>
        
        <Tooltip>
          <TooltipTrigger asChild>
            <Button mode="icon" variant="ghost">
              <Settings />
            </Button>
          </TooltipTrigger>
          <TooltipContent side="right">AI Settings</TooltipContent>
        </Tooltip>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <SectionHeader label="Quick Actions" />
      <div className="space-y-1">
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start gap-2 text-muted-foreground hover:text-foreground"
        >
          <Star className="size-4" />
          <span className="text-sm">Favorites</span>
          <Badge variant="info" appearance="outline" size="sm" className="ml-auto">2</Badge>
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start gap-2 text-muted-foreground hover:text-foreground"
        >
          <Trash2 className="size-4" />
          <span className="text-sm">Clear History</span>
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start gap-2 text-muted-foreground hover:text-foreground"
        >
          <History className="size-4" />
          <span className="text-sm">Chat History</span>
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start gap-2 text-muted-foreground hover:text-foreground"
        >
          <FileText className="size-4" />
          <span className="text-sm">Templates</span>
          <Badge variant="destructive" appearance="outline" size="sm" className="ml-auto">5</Badge>
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start gap-2 text-muted-foreground hover:text-foreground"
        >
          <Settings className="size-4" />
          <span className="text-sm">AI Settings</span>
        </Button>
      </div>
    </div>
  );
}


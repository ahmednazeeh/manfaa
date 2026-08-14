import { cn } from "@/lib/utils";
import { useLayout } from "./context";
import { ScrollArea } from "@/components/ui/scroll-area";
import { SidebarTodoList } from "./sidebar-todo-list";
import { SidebarTags } from "./sidebar-tags";
import { NewTask } from "./new-task";

export function SidebarContent() {
  const { isSidebarOpen, isMobile } = useLayout();
  const isCollapsed = isMobile ? false : !isSidebarOpen;

  return (
    <ScrollArea
      className={cn(
        "shrink-0 w-full",
        isSidebarOpen ? "h-[calc(100vh-8.5rem)] lg:h-[calc(100vh-17.5rem)]" : "h-[calc(100vh-9.55rem)]"
      )}
    >
      <div className="p-3 space-y-3">
        <NewTask isCollapsed={isCollapsed} />

        <SidebarTodoList isCollapsed={isCollapsed} />

        {!isCollapsed && <SidebarTags />}
      </div>
    </ScrollArea>
  );
}

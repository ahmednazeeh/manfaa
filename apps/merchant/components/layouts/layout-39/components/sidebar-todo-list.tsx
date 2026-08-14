import { useState } from 'react';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { CalendarCheck, ListTodo, Clock3, Flag, CheckCircle2 } from 'lucide-react';
import {
  AccordionMenu,
  AccordionMenuIndicator,
  AccordionMenuSub,
  AccordionMenuSubTrigger,
  AccordionMenuSubContent,
  AccordionMenuItem,
} from '@/components/ui/accordion-menu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import Link from 'next/link';

interface SidebarTodoListProps {
  isCollapsed: boolean;
}

type TodoListItem = {
  id: number;
  title: string;
  icon: LucideIcon;
  badge: "primary" | "success" | "warning" | "destructive" | "info" | "secondary";
  count: number;
};

export function SidebarTodoList({ isCollapsed }: SidebarTodoListProps) {
  const [activeItem, setActiveItem] = useState<number>(1);

  const todoLists: TodoListItem[] = [
    {
      id: 1,
      title: 'All Tasks',
      icon: ListTodo,
      badge: 'primary',
      count: 42,
    },
    {
      id: 2,
      title: 'Today',
      icon: CalendarCheck,
      badge: 'success',
      count: 6,
    },
    {
      id: 3,
      title: 'Upcoming',
      icon: Clock3,
      badge: 'warning',
      count: 14
    },
    {
      id: 4,
      title: 'Priority',
      icon: Flag,
      badge: 'destructive',
      count: 4
    },
    {
      id: 5,
      title: 'Completed',
      icon: CheckCircle2,
      badge: 'info',
      count: 18
    },
  ];

  if (isCollapsed) {
    return (
      <div className="flex flex-col gap-1">
        {todoLists.map((todoList, index) => (
          <Tooltip key={index}>
            <TooltipTrigger asChild>
              <Button
                asChild
                variant="ghost"
                size="icon"
                className={cn(
                  "h-8.5 w-8.5",
                  activeItem === todoList.id
                    ? "bg-muted text-foreground"
                    : "text-muted-foreground hover:text-foreground hover:bg-muted"
                )}
                onClick={() => setActiveItem(todoList.id)}
              >
                <Link href="#">
                  <todoList.icon
                    className={cn(
                      "size-4 transition-transform duration-200 hover:scale-110 hover:text-primary",
                      activeItem === todoList.id ? "text-primary" : "text-muted-foreground"
                    )}
                  />
                </Link>
              </Button>
            </TooltipTrigger>
            <TooltipContent side="right">
              <div className="flex items-center gap-2">
                <span>{todoList.title}</span>
                <Badge appearance="outline" variant={activeItem === todoList.id ? todoList.badge : todoList.badge} size="sm">
                  {todoList.count}
                </Badge>
              </div>
            </TooltipContent>
          </Tooltip>
        ))}
      </div>
    );
  }

  return (
    <AccordionMenu
      type="single"
      collapsible
      defaultValue="todoLists-trigger"
      selectedValue="todoLists-trigger"
      className="space-y-7.5"
      classNames={{
        item: 'h-8.5 px-2.5 text-sm font-normal text-foreground hover:text-primary data-[selected=true]:bg-muted data-[selected=true]:text-foreground [&[data-selected=true]_svg]:opacity-100 my-0.5',
        subTrigger: 'text-xs font-normal text-muted-foreground hover:bg-transparent',
        subContent: 'ps-0',
      }}
    >
      <AccordionMenuSub value="todoLists">
        <AccordionMenuSubTrigger value="todoLists-trigger">
          <span>My Lists</span>
          <AccordionMenuIndicator />
        </AccordionMenuSubTrigger>
        <AccordionMenuSubContent type="single" collapsible parentValue="todoLists-trigger">
          {todoLists.map((todoList, index) => (
            <AccordionMenuItem
              key={index}
              asChild
              value={`todoList-${todoList.id}`}
              onClick={() => setActiveItem(todoList.id)}
            >
              <Link
                href="#"
                onClick={() => setActiveItem(todoList.id)}
                className={cn(
                  "group flex w-full items-center gap-2",
                  activeItem === todoList.id
                    ? "bg-muted text-foreground"
                    : "text-muted-foreground hover:text-foreground hover:bg-muted"
                )}
              >
                <todoList.icon
                  className={cn(
                    "size-4 transition-transform duration-200 group-hover:scale-110 group-hover:text-primary",
                    activeItem === todoList.id ? "text-primary" : "text-muted-foreground"
                  )}
                />
                {todoList.title}
                <Badge appearance="outline" variant={activeItem === todoList.id ? todoList.badge : todoList.badge} size="sm" className="ms-auto">
                  {todoList.count}
                </Badge>
              </Link>
            </AccordionMenuItem>
          ))}
        </AccordionMenuSubContent>
      </AccordionMenuSub>
    </AccordionMenu>
  );
}

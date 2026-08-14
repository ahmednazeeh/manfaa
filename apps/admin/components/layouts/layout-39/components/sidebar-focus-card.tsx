import { Progress } from "@/components/ui/progress";

export function SidebarFocusCard() {
  const focusProgress = {
    completed: 18,
    total: 32,
    title: "Launch campaign",
    description: "Next milestone due in 3 days",
  };
  const value = (focusProgress.completed / focusProgress.total) * 100;

  return (
    <div className="space-y-3 rounded-xl border border-dashed border-input bg-muted/40 p-3 w-full min-w-full overflow-hidden truncate">
      <div className="flex items-center justify-between text-xs font-medium text-muted-foreground">
        <span>Focus progress</span>
        <span>{focusProgress.completed} / {focusProgress.total}</span>
      </div>
      <Progress value={value} indicatorClassName="bg-blue-500" className="h-2" />
      <div className="space-y-1">
        <p className="text-sm font-semibold text-foreground">{focusProgress.title}</p>
        <p className="text-xs text-muted-foreground">{focusProgress.description}</p>
      </div>
    </div>
  );
}

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { LucideIcon } from "lucide-react";
import {
  ChevronDown,
  Brain,
  Workflow,
  Code2,
} from "lucide-react";

const modelOptions = [
  {
    id: "gpt-4",
    label: "GPT-4",
    tagline: "Smarter and faster",
    description: "Flagship multimodal model for reasoning-heavy chat, voice, and vision.",
    icon: Brain,
    badge: { label: "Default", variant: "info" as const },
  },
  {
    id: "gpt-4-mini",
    label: "GPT-4 mini",
    tagline: "Quick drafts & prototyping",
    description: "Lightweight and cost-efficient for rapid iteration across teams.",
    icon: Workflow,
    badge: { label: "Fast", variant: "success" as const },
  },
  {
    id: "gpt-4-turbo",
    label: "GPT-4 Turbo",
    tagline: "Developer ready",
    description: "Stable API surface with JSON mode, structured output, and tools.",
    icon: Code2,
  },
] satisfies Array<{
  id: string;
  label: string;
  tagline: string;
  description: string;
  icon: LucideIcon;
  badge?: { label: string; variant?: "info" | "success" | "warning" };
}>;

interface ModelSelectorProps {
  selectedModelId: string;
  onModelChange: (modelId: string) => void;
}

export function ModelSelector({ selectedModelId, onModelChange }: ModelSelectorProps) {
  const selectedModel = modelOptions.find((model) => model.id === selectedModelId) || modelOptions[0];

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          appearance="ghost"
          autoHeight
          className="group rounded-2xl border-border bg-background/60 py-1.5 hover:bg-accent/60"
        >
          <div className="flex items-center gap-3">
            <div className="flex flex-col items-start">
              <span className="text-sm font-semibold text-foreground leading-5">{selectedModel.label}</span>
            </div>

            <ChevronDown className="size-4" />
          </div>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-80" sideOffset={12}>
        {modelOptions.map((model) => {
          const Icon = model.icon;
          return (
            <DropdownMenuItem
              key={model.id}
              data-active={model.id === selectedModel.id}
              className="items-start gap-2.5 rounded-xl px-2 py-2.5 transition-all data-[active=true]:bg-muted cursor-pointer"
              onClick={() => onModelChange(model.id)}
            >
              <div className="flex size-8 items-center justify-center rounded-full border border-input">
                <Icon className="size-4" />
              </div>
              

              <div className="flex flex-1 flex-col gap-1">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-semibold text-foreground">{model.label}</span>
                  {model.badge ? (
                    <Badge variant={model.badge.variant} appearance="outline" size="xs">
                      {model.badge.label}
                    </Badge>
                  ) : null}
                </div>
                <span className="text-sm font-medium text-muted-foreground">{model.tagline}</span>
                <span className="text-xs text-muted-foreground/80">
                  {model.description}
                </span>
              </div>
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}


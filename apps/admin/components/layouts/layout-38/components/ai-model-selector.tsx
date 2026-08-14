import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { Brain, Cpu, Sparkles } from "lucide-react";
import { SectionHeader } from "./section-header";
import { AIModel } from "./types";

interface AIModelSelectorProps {
  selectedModel: string;
  onModelSelect: (modelId: string) => void;
}

export const AI_MODELS: AIModel[] = [
  { id: "gpt-4", name: "GPT-4", icon: Brain, color: "text-purple-500" },
  { id: "claude", name: "Claude", icon: Cpu, color: "text-blue-500" },
  { id: "gemini", name: "Gemini", icon: Sparkles, color: "text-orange-500" },
];

export function AIModelSelector({ selectedModel, onModelSelect }: AIModelSelectorProps) {
  return (
    <div className="space-y-2">
      <SectionHeader label="AI Model" />
      <div className="grid grid-cols-3 gap-2">
        {AI_MODELS.map((model) => {
          const Icon = model.icon;
          const isSelected = selectedModel === model.id;
          return (
            <Button
              key={model.id}
              variant="outline"
              size="sm"
              autoHeight
              className={cn(
                "flex-col gap-1 py-2",
                isSelected && "bg-muted shadow-lg shadow-black/5"
              )}
              onClick={() => onModelSelect(model.id)}
            >
              <Icon className={cn("size-4", model.color)} />
              <span className="text-xs leading-tight">{model.name}</span>
            </Button>
          );
        })}
      </div>
    </div>
  );
}


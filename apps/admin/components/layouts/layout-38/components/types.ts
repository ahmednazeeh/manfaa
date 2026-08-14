import { LucideIcon } from 'lucide-react';

export interface ChatThread {
  id: string;
  title: string;
  model: string;
  timestamp: string;
  isPinned?: boolean;
  icon: LucideIcon;
  messageCount?: number;
}

export interface AIModel {
  id: string;
  name: string;
  icon: LucideIcon;
  color: string;
}

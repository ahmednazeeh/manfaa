import { ScrollArea } from "@/components/ui/scroll-area";
import { Separator } from "@/components/ui/separator";
import { useState } from "react";
import { NewChatButton } from "./new-chat-button";
import { AIModelSelector } from "./ai-model-selector";
import { PinnedChats } from "./pinned-chats";
import { RecentChats } from "./recent-chats";
import { QuickActions } from "./quick-actions";
import { useLayout } from "./context";
import { cn } from "@/lib/utils";

export function SidebarContent() {
  const { isSidebarOpen } = useLayout();
  const [selectedChat, setSelectedChat] = useState<string | null>("1");
  const [selectedModel, setSelectedModel] = useState("gpt-4");

  const handleChatDelete = (chatId: string) => {
    console.log("Delete chat:", chatId);
  };

  return (
    <ScrollArea 
      className={cn(
        "shrink-0 w-full",
        isSidebarOpen ? "h-[calc(100vh-1rem)] lg:h-[calc(100vh-9.5rem)]" : "h-[calc(100vh-9rem)]"
      )}
    >
      <div className="p-2.5 space-y-3.5">
        <NewChatButton isCollapsed={!isSidebarOpen} />

        {isSidebarOpen && (
          <>
            <AIModelSelector 
              selectedModel={selectedModel}
              onModelSelect={setSelectedModel}
            />

            <Separator className="my-4 opacity-80" />

            <PinnedChats 
              selectedChat={selectedChat}
              onChatSelect={setSelectedChat}
            />

            <Separator className="my-4 opacity-80" />

            <RecentChats 
              selectedChat={selectedChat}
              onChatSelect={setSelectedChat}
              onChatDelete={handleChatDelete}
            />

            <Separator className="my-4 opacity-80" />
          </>
        )}

        <QuickActions isCollapsed={!isSidebarOpen} />
      </div>
    </ScrollArea>
  );
}

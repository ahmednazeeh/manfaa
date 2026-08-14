import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Avatar, AvatarFallback, AvatarImage, AvatarIndicator, AvatarStatus } from "@/components/ui/avatar";
import { toAbsoluteUrl } from "@/lib/helpers";
import { MessageSquarePlus, History, Settings, HelpCircle, LogOut, Moon, Sun } from "lucide-react";
import { useTheme } from "next-themes";

interface UserDropdownMenuProps {
  isCollapsed?: boolean;
}

export function UserDropdownMenu({ isCollapsed = false }: UserDropdownMenuProps) {
  const { theme, setTheme } = useTheme();
  
  const toggleTheme = () => {
    setTheme(theme === "light" ? "dark" : "light");
  };
	
  return (
    <DropdownMenu>
			{isCollapsed ? (
				<DropdownMenuTrigger className="cursor-pointer">
					<Avatar className="size-9">
						<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
						<AvatarFallback>AI</AvatarFallback>
						<AvatarIndicator className="-end-1.5 -top-1.5">
							<AvatarStatus variant="online" className="size-2.5" />
						</AvatarIndicator>
					</Avatar>
				</DropdownMenuTrigger>
			) : (
				<DropdownMenuTrigger className="cursor-pointer" asChild>
					<div className="flex items-center gap-2.5 lg:px-2 py-1.5 rounded-md hover:bg-muted transition-colors w-full">
						<Avatar className="size-9">
							<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
							<AvatarFallback>AI</AvatarFallback>
							<AvatarIndicator className="-end-1.5 -top-1.5">
								<AvatarStatus variant="online" className="size-2.5" />
							</AvatarIndicator>
						</Avatar>
						<div className="hidden lg:flex flex-col items-start flex-1 min-w-0">
							<span className="text-sm font-semibold text-foreground truncate w-full">AI Assistant</span>
							<span className="text-xs text-muted-foreground truncate w-full">Always here to help</span>
						</div>
					</div>
				</DropdownMenuTrigger>
			)}
			<DropdownMenuContent className="w-56" side={isCollapsed ? "top" : "top"} align="start" sideOffset={11}>
				{/* User Information Section */}
				<div className="flex items-center gap-2.5 px-2.5 py-1.5">
					<Avatar>
						<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
						<AvatarFallback>AI</AvatarFallback>
						<AvatarIndicator className="-end-1.5 -top-1.5">
							<AvatarStatus variant="online" className="size-2.5" />
						</AvatarIndicator>
					</Avatar>
					<div className="flex flex-col items-start">
						<span className="text-sm font-semibold text-foreground">AI Assistant</span>
						<span className="text-xs text-muted-foreground">Always here to help</span>
					</div>
				</div>

				<DropdownMenuSeparator />

				{/* Chat Actions */}
				<DropdownMenuItem>
					<MessageSquarePlus/>
					<span>New Chat</span>
				</DropdownMenuItem>

				<DropdownMenuItem>
					<History/>
					<span>Chat History</span>
				</DropdownMenuItem>

				<DropdownMenuSeparator />

				{/* Settings */}
				<DropdownMenuItem>
					<Settings/>
					<span>Settings</span>
				</DropdownMenuItem>

				<DropdownMenuItem>
					<HelpCircle/>
					<span>Help & Support</span>
				</DropdownMenuItem>

				<DropdownMenuSeparator />

				{/* Theme Toggle */}
				<DropdownMenuItem onClick={toggleTheme}>
					{theme === "light" ? <Moon className="size-4" /> : <Sun className="size-4" />}
					<span>{theme === "light" ? "Dark mode" : "Light mode"}</span>
				</DropdownMenuItem>

				<DropdownMenuSeparator />

				{/* Action Items */}
				<DropdownMenuItem>
					<LogOut/>
					<span>Sign out</span>
				</DropdownMenuItem>
			</DropdownMenuContent>
		</DropdownMenu>
  );
}
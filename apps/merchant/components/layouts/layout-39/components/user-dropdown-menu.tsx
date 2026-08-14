import { useTheme } from "next-themes";
import { toAbsoluteUrl } from "@/lib/helpers";
import { useLayout } from "./context";
import {
	Avatar,
	AvatarFallback,
	AvatarImage,
	AvatarIndicator,
	AvatarStatus,
} from "@/components/ui/avatar";
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuSeparator,
	DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {Plus, CheckSquare, ListTodo, Settings, HelpCircle, LogOut, Moon, Sun } from "lucide-react";

interface UserDropdownMenuProps {
  isCollapsed?: boolean;
}

export function UserDropdownMenu({ isCollapsed = false }: UserDropdownMenuProps) {
	const {isMobile} = useLayout();
  const { theme, setTheme } = useTheme();
  
  const toggleTheme = () => {
    setTheme(theme === "light" ? "dark" : "light");
  };
	
  return (
    <DropdownMenu>
			{isCollapsed ? (
				<DropdownMenuTrigger className="cursor-pointer py-1.5">
					<Avatar className="size-9">
						<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
						<AvatarFallback>AD</AvatarFallback>
						<AvatarIndicator className="-end-1.5 -top-1.5">
							<AvatarStatus variant="online" className="size-2.5" />
						</AvatarIndicator>
					</Avatar>
				</DropdownMenuTrigger>
			) : (
				<DropdownMenuTrigger className="cursor-pointer" asChild>
					<div className="flex items-center flex-nowrap gap-2.5 lg:px-2 py-1.5 rounded-md hover:bg-muted transition-colors w-full overflow-hidden">
						<Avatar className="size-9">
							<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
							<AvatarFallback>AD</AvatarFallback>
							<AvatarIndicator className="-end-1.5 -top-1.5">
								<AvatarStatus variant="online" className="size-2.5" />
							</AvatarIndicator>
						</Avatar>
						<div className="hidden lg:flex flex-col items-start flex-1 min-w-0">
							<span className="text-sm font-semibold text-foreground truncate w-full">Alex Doe</span>
							<span className="text-xs text-muted-foreground truncate w-full">alex.doe@gmail.com</span>
						</div>
					</div>
				</DropdownMenuTrigger>
			)}
			<DropdownMenuContent className="w-56" side="top" align={isMobile ? "end" : "start"} sideOffset={11}>
				{/* User Information Section */}
				<div className="flex items-center gap-2.5 px-2.5 py-1.5">
					<Avatar>
						<AvatarImage src={toAbsoluteUrl('/media/avatars/300-2.png')} alt="@reui" />
						<AvatarFallback>AD</AvatarFallback>
						<AvatarIndicator className="-end-1.5 -top-1.5">
							<AvatarStatus variant="online" className="size-2.5" />
						</AvatarIndicator>
					</Avatar>
					<div className="flex flex-col items-start">
						<span className="text-sm font-semibold text-foreground">Alex Doe</span>
						<span className="text-xs text-muted-foreground">alex.doe@gmail.com</span>
					</div>
				</div>

				<DropdownMenuSeparator />

				{/* Todo Actions */}
				<DropdownMenuItem>
					<Plus className="size-4" />
					<span>New Task</span>
				</DropdownMenuItem>

				<DropdownMenuItem>
					<ListTodo className="size-4" />
					<span>My Tasks</span>
				</DropdownMenuItem>

				<DropdownMenuItem>
					<CheckSquare className="size-4" />
					<span>Completed Tasks</span>
				</DropdownMenuItem>

				<DropdownMenuSeparator />

				{/* Settings */}
				<DropdownMenuItem>
					<Settings className="size-4" />
					<span>Settings</span>
				</DropdownMenuItem>

				<DropdownMenuItem>
					<HelpCircle className="size-4" />
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
					<LogOut className="size-4" />
					<span>Sign out</span>
				</DropdownMenuItem>
			</DropdownMenuContent>
		</DropdownMenu>
  );
}

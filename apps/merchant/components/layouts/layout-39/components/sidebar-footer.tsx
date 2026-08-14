import { useLayout } from "./context";
import { UserDropdownMenu } from "./user-dropdown-menu";
import { SidebarFocusCard } from "./sidebar-focus-card";

export function SidebarFooter() {
  const {isMobile, isSidebarOpen} = useLayout();
  const isCollapsed = isMobile ? false : !isSidebarOpen;

  return (
    <div className="shrink-0 px-2.5 py-2.5 space-y-3">
      {!isCollapsed && <SidebarFocusCard />}
      {!isMobile && <UserDropdownMenu isCollapsed={!isSidebarOpen} />}
    </div>
  );
}

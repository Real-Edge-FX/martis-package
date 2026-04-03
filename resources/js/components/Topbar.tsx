import { useRef } from "react"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { Button } from "primereact/button"
import { Menu } from "primereact/menu"
import type { MenuItem } from "primereact/menuitem"
import { useTranslation } from "react-i18next"

export function Topbar() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { t } = useTranslation("navigation")
  const menuRef = useRef<Menu>(null)

  const userMenuItems: MenuItem[] = [
    {
      label: user?.name ?? user?.email ?? "",
      className: "font-bold pointer-events-none opacity-80",
      disabled: true,
    },
    {
      label: user?.email ?? "",
      className: "text-xs pointer-events-none opacity-60",
      disabled: true,
    },
    { separator: true },
    {
      label: theme === "dark" ? t("light_mode") : t("dark_mode"),
      icon: `pi pi-${theme === "dark" ? "sun" : "moon"}`,
      command: () => toggle(),
    },
    { separator: true },
    {
      label: t("logout"),
      icon: "pi pi-sign-out",
      className: "text-red-500",
      command: () => void logout(),
    },
  ]

  return (
    <header className="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-5 dark:border-gray-800 dark:bg-gray-900">
      <Breadcrumbs />

      <div className="flex items-center gap-2">
        <Button
          icon={`pi pi-${theme === "dark" ? "sun" : "moon"}`}
          onClick={toggle}
          aria-label={t("toggle_theme")}
          rounded
          text
          severity="secondary"
          size="small"
        />

        {/* User avatar + dropdown menu */}
        <Menu model={userMenuItems} popup ref={menuRef} className="min-w-[200px]" />
        <div
          className="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-1.5 cursor-pointer hover:bg-gray-100 transition-colors dark:bg-gray-800 dark:hover:bg-gray-700"
          onClick={(e) => menuRef.current?.toggle(e)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") menuRef.current?.toggle(e as unknown as React.SyntheticEvent) }}
        >
          <div className="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
            {(user?.name ?? user?.email ?? "?")[0].toUpperCase()}
          </div>
          <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {user?.name ?? user?.email}
          </span>
          <i className="pi pi-chevron-down text-xs text-gray-400" />
        </div>
      </div>
    </header>
  )
}

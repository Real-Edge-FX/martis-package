import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import type { NavigationGroup } from "@/types"

/**
 * useMartisNav — exposes all navigation data for custom layouts.
 *
 * Use this hook when building a custom layout component to access:
 * - navigation: resource groups and items
 * - user: current authenticated user
 * - brand: application name and logo
 * - theme: current theme and toggle function
 * - logout: logout function
 * - config: full Martis config
 *
 * Example:
 *   function MyCustomLayout({ children }: { children: React.ReactNode }) {
 *     const { navigation, user, brand, theme, logout } = useMartisNav()
 *     return (
 *       <div>
 *         <nav>{navigation.map(g => ...)}</nav>
 *         <main>{children}</main>
 *       </div>
 *     )
 *   }
 */
export function useMartisNav() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()

  const { data: navigation = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  return {
    navigation,
    user,
    logout,
    brand: {
      name: config.brand ?? "Martis",
      logo: config.logo ?? null,
    },
    theme: {
      current: theme,
      toggle,
    },
    config,
  }
}

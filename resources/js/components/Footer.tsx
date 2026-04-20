import { config } from "@/lib/config"
import { componentRegistry } from "@/lib/componentRegistry"
import { useTranslation } from "react-i18next"
import type { ComponentType } from "react"

function DefaultFooter() {
  const { t } = useTranslation("navigation")
  const footer = config.footer
  const brand = config.brand ?? "Martis"

  if (footer?.enabled === false) return null

  const text = footer?.text ?? t("footer_default", "\u00a9 {{brand}} \u00b7 Powered by Martis", { brand })

  return (
    <footer className="martis-shell-pagefooter">
      <span>{text}</span>
    </footer>
  )
}

export function Footer() {
  const configured = config.layout?.components?.footer
  if (configured && componentRegistry.has(configured)) {
    const CustomFooter = componentRegistry.resolve(configured) as ComponentType
    return <CustomFooter />
  }
  if (componentRegistry.has("layout:footer")) {
    const CustomFooter = componentRegistry.resolve("layout:footer") as ComponentType
    return <CustomFooter />
  }

  return <DefaultFooter />
}

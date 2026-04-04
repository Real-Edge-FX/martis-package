import {
  Database,
  Article,
  Users,
  FolderSimple,
  Gear,
  ChartBar,
  Tag,
  Image,
  FileText,
  ShoppingCart,
  House,
  Envelope,
  Bell,
  Calendar,
  MapPin,
  CreditCard,
  Star,
  ChatCircle,
  type IconProps,
} from "@phosphor-icons/react"

const iconMap: Record<string, React.ComponentType<IconProps>> = {
  database: Database,
  article: Article,
  users: Users,
  folders: FolderSimple,
  gear: Gear,
  "chart-bar": ChartBar,
  tag: Tag,
  image: Image,
  "file-text": FileText,
  "shopping-cart": ShoppingCart,
  house: House,
  envelope: Envelope,
  bell: Bell,
  calendar: Calendar,
  "map-pin": MapPin,
  "credit-card": CreditCard,
  star: Star,
  chat: ChatCircle,
}

interface ResourceIconProps extends IconProps {
  name: string | null | undefined
}

export function ResourceIcon({ name, ...props }: ResourceIconProps) {
  const Icon = iconMap[name ?? "database"] ?? Database
  return <Icon {...props} />
}

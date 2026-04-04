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

interface ResourceIconProps {
  iconName: string | null | undefined
  size?: number
  className?: string
  weight?: IconProps["weight"]
}

export function ResourceIcon({ iconName, size, className, weight }: ResourceIconProps) {
  const Icon = iconMap[iconName ?? "database"] ?? Database
  return <Icon size={size} className={className} weight={weight} />
}

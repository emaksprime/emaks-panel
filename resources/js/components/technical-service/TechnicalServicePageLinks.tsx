import { Link, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import type { SharedPageProps } from '@/types'

const links = [
  { href: '/technical-service', label: 'Talepler' },
  { href: '/technical-service/dashboard', label: 'Operasyon Dashboard' },
  { href: '/technical-service/serial-query', label: 'Seri No Sorgu' },
  { href: '/activation-code-search', label: 'Aktivasyon Kodu Bul' },
  { href: '/technical-service/technicians', label: 'Teknisyen Yönetimi' },
  { href: '/technical-service/earnings', label: 'Hakedişler' },
  { href: '/technical-service/admin', label: 'Teknik Servis Admin' },
]

export function TechnicalServicePageLinks() {
  const { panelNavigation } = usePage<SharedPageProps>().props
  const visibleHrefs = new Set(
    panelNavigation.groups.flatMap((group) => group.items.map((item) => item.href)),
  )

  return links
    .filter((link) => visibleHrefs.has(link.href))
    .map((link) => (
      <Button key={link.href} asChild variant="secondary">
        <Link href={link.href}>{link.label}</Link>
      </Button>
    ))
}

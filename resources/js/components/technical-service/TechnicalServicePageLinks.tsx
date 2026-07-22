import { Link, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import type { SharedPageProps } from '@/types'

type TechnicalServiceLink = {
  href: string
  label: string
  resourceCode: string
  group: 'primary' | 'operational' | 'pilot' | 'admin'
  badge?: string
}

const links: TechnicalServiceLink[] = [
  {
    href: '/technical-service',
    label: 'Operasyon Merkezi',
    resourceCode: 'technical_service',
    group: 'primary',
  },
  { href: '/technical-service/field', label: 'Usta İşleri', resourceCode: 'technical_service_dashboard', group: 'operational' },
  { href: '/technical-service/serial-query', label: 'Seri No Sorgu', resourceCode: 'technical_service_serial_query', group: 'operational' },
  { href: '/technical-service/qr-products', label: 'Ürün QR Yönetimi', resourceCode: 'technical_service_manage', group: 'operational' },
  { href: '/technical-service/technicians', label: 'Teknisyen Yönetimi', resourceCode: 'technical_service_technicians', group: 'operational' },
  { href: '/technical-service/earnings', label: 'Hakedişler', resourceCode: 'technical_service_earnings', group: 'operational' },
  {
    href: '/technical-service/dashboard',
    label: 'Operasyon Dashboard — Pilot',
    resourceCode: 'technical_service_dashboard',
    group: 'pilot',
    badge: 'Geliştiriliyor',
  },
  { href: '/technical-service/admin', label: 'Teknik Servis Admin', resourceCode: 'technical_service_admin', group: 'admin' },
]

const groupLabels: Record<TechnicalServiceLink['group'], string> = {
  primary: 'Ana',
  operational: 'Operasyon',
  pilot: 'Pilot',
  admin: 'Yönetim',
}

const groupOrder: TechnicalServiceLink['group'][] = ['primary', 'operational', 'pilot', 'admin']

const normalizePath = (path: string) => (path.split('?')[0] || '/').replace(/\/+$/, '') || '/'

const isPathActive = (currentPath: string, href: string) => {
  const target = normalizePath(href)

  return currentPath === target
}

export function TechnicalServicePageLinks() {
  const page = usePage<SharedPageProps>()
  const { panelNavigation } = page.props
  const currentPath = normalizePath(page.url)
  const grantedResources = new Set(panelNavigation?.resources ?? [])
  const isSuperAdmin = panelNavigation?.role?.isSuperAdmin === true
  const visibleLinks = links.filter((link) => isSuperAdmin || grantedResources.has(link.resourceCode))
  const visibleGroups = groupOrder
    .map((group) => ({ group, links: visibleLinks.filter((link) => link.group === group) }))
    .filter(({ links: groupLinks }) => groupLinks.length > 0)

  if (visibleLinks.length === 0) {
    return null
  }

  return (
    <nav aria-label="Teknik Servis Alt Menüsü" className="overflow-x-auto pb-1">
      <div className="flex min-w-max items-stretch gap-3">
        {visibleGroups.map(({ group, links: groupLinks }) => (
          <section
            key={group}
            aria-label={groupLabels[group]}
            className="rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm"
          >
            <p className="px-2 pb-1 text-[10px] font-semibold uppercase text-slate-400">
              {groupLabels[group]}
            </p>
            <div className="flex items-center gap-1">
              {groupLinks.map((link) => {
                const active = isPathActive(currentPath, link.href)

                return (
                  <Button
                    key={link.href}
                    asChild
                    variant="ghost"
                    className={[
                      'h-10 rounded-md border px-3 text-sm font-semibold whitespace-nowrap transition-colors',
                      active
                        ? 'border-[#06143A] bg-[#06143A] text-white hover:bg-[#0b1d51] hover:text-white'
                        : 'border-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-950',
                    ].join(' ')}
                  >
                    <Link href={link.href}>
                      <span>{link.label}</span>
                      {link.badge ? (
                        <span className={active ? 'ml-2 text-[10px] text-amber-200' : 'ml-2 text-[10px] text-amber-700'}>
                          {link.badge}
                        </span>
                      ) : null}
                    </Link>
                  </Button>
                )
              })}
            </div>
          </section>
        ))}
      </div>
    </nav>
  )
}

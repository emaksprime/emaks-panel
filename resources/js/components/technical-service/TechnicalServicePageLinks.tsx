import { Link, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import type { SharedPageProps } from '@/types'

type TechnicalServiceLink = {
  href: string
  label: string
  activeWhen?: string[]
  hrefCandidates?: string[]
  visibleWhen?: string[]
}

const links: TechnicalServiceLink[] = [
  {
    href: '/technical-service',
    hrefCandidates: ['/technical-service', '/technical-service/dashboard'],
    activeWhen: ['/technical-service/dashboard'],
    label: 'Operasyon Merkezi',
    visibleWhen: ['/technical-service/dashboard'],
  },
  { href: '/technical-service/field', label: 'Usta İşleri', visibleWhen: ['/technical-service/dashboard'] },
  { href: '/technical-service/serial-query', label: 'Seri No Sorgu' },
  { href: '/technical-service/technicians', label: 'Teknisyen Yönetimi' },
  { href: '/technical-service/earnings', label: 'Hakedişler' },
  { href: '/technical-service/admin', label: 'Teknik Servis Admin' },
]

const normalizePath = (path: string) => (path.split('?')[0] || '/').replace(/\/+$/, '') || '/'

const isPathActive = (currentPath: string, href: string) => {
  const target = normalizePath(href)

  return currentPath === target || currentPath.startsWith(`${target}/`)
}

export function TechnicalServicePageLinks() {
  const page = usePage<SharedPageProps>()
  const { panelNavigation } = page.props
  const groups = panelNavigation?.groups ?? []
  const currentPath = normalizePath(page.url)
  const visibleHrefs = new Set(groups.flatMap((group) => group.items.map((item) => item.href)))
  const hasNavigationState = visibleHrefs.size > 0

  const visibleLinks = links.filter((link) => {
    if (!hasNavigationState) {
      return true
    }

    return visibleHrefs.has(link.href) || link.visibleWhen?.some((href) => visibleHrefs.has(href))
  })

  const resolveHref = (link: TechnicalServiceLink) => {
    if (!hasNavigationState || !link.hrefCandidates) {
      return link.href
    }

    return link.hrefCandidates.find((href) => visibleHrefs.has(href)) ?? link.href
  }

  const isActiveLink = (link: TechnicalServiceLink) =>
    [link.href, ...(link.activeWhen ?? [])].some((href) => isPathActive(currentPath, href))

  if (visibleLinks.length === 0) {
    return null
  }

  return (
    <nav aria-label="Teknik Servis Alt Menüsü" className="overflow-x-auto">
      <div className="inline-flex min-w-full items-center rounded-[28px] border border-white bg-white p-1.5 shadow-[0_14px_36px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/70 xl:min-w-max">
        <div className="flex min-w-max items-center gap-2">
          {visibleLinks.map((link) => {
            const active = isActiveLink(link)

            return (
              <Button
                key={link.href}
                asChild
                variant="ghost"
                className={[
                  'h-10 rounded-[18px] border px-4 text-sm font-semibold transition-all duration-200',
                  active
                    ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_10px_22px_rgba(6,20,58,0.18)] hover:bg-[#0b1d51] hover:text-white'
                    : 'border-transparent bg-transparent text-slate-600 hover:border-slate-200 hover:bg-[#F8FAFD] hover:text-slate-950',
                ].join(' ')}
              >
                <Link href={resolveHref(link)}>{link.label}</Link>
              </Button>
            )
          })}
        </div>
      </div>
    </nav>
  )
}

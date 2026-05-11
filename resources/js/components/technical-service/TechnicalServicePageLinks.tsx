import { Link, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import type { SharedPageProps } from '@/types'

const links = [
  { href: '/technical-service', label: 'Operasyon Merkezi' },
  { href: '/technical-service/field', label: 'Saha İşleri' },
  { href: '/technical-service/serial-query', label: 'Seri No Sorgu' },
  { href: '/activation-code-search', label: 'Aktivasyon Kodu Bul' },
  { href: '/technical-service/technicians', label: 'Teknisyen Yönetimi' },
  { href: '/technical-service/earnings', label: 'Hakedişler' },
  { href: '/technical-service/admin', label: 'Teknik Servis Admin' },
]

const normalizePath = (path: string) =>
  (path.split('?')[0] || '/').replace(/\/+$/, '') || '/'

export function TechnicalServicePageLinks() {
  const page = usePage<SharedPageProps>()
  const { panelNavigation } = page.props
  const { url } = page
  const groups = panelNavigation?.groups ?? []
  const currentPath = normalizePath(url)
  const visibleHrefs = new Set(groups.flatMap((group) => group.items.map((item) => item.href)))
  const visibleLinks = visibleHrefs.size > 0
    ? links.filter((link) => visibleHrefs.has(link.href))
    : links

  const isActiveLink = (href: string) => {
    const target = normalizePath(href)

    if (target === '/technical-service') {
      return currentPath === target || currentPath === '/technical-service/dashboard'
    }

    return currentPath === target || currentPath.startsWith(`${target}/`)
  }

  return (
    <nav aria-label="Teknik Servis Alt Menüsü" className="overflow-x-auto">
      <div className="inline-flex min-w-full items-center rounded-[24px] border border-white/80 bg-white/90 p-2 shadow-[0_16px_40px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur xl:min-w-max">
        <div className="flex min-w-max items-center gap-2">
          {visibleLinks.map((link) => {
            const active = isActiveLink(link.href)

            return (
              <Button
                key={link.href}
                asChild
                variant="ghost"
                className={[
                  'h-11 rounded-2xl border px-4 text-sm font-medium transition-all duration-200',
                  active
                    ? 'border-slate-950 bg-slate-950 text-white shadow-[0_10px_24px_rgba(15,23,42,0.18)] hover:bg-slate-900 hover:text-white'
                    : 'border-transparent bg-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-950',
                ].join(' ')}
              >
                <Link href={link.href}>{link.label}</Link>
              </Button>
            )
          })}
        </div>
      </div>
    </nav>
  )
}


import type { ReactNode } from 'react'

export function TechnicalServiceShell({
  children,
  maxWidth = 'max-w-none',
}: {
  children: ReactNode
  maxWidth?: string
}) {
  return (
    <div className="relative min-h-screen overflow-hidden bg-[#eaf1f8]">
      <div className="pointer-events-none absolute inset-x-0 top-0 h-80 bg-[radial-gradient(circle_at_top_left,_rgba(15,23,42,0.14),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(37,99,235,0.12),_transparent_34%)]" />
      <div className={['relative w-full space-y-6 px-4 py-6 md:px-6 xl:px-8 2xl:px-10', maxWidth].join(' ')}>
        {children}
      </div>
    </div>
  )
}

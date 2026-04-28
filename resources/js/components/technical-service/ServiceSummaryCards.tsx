import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { SummaryItem } from './types'

export function ServiceSummaryCards({ items }: { items: SummaryItem[] }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {items.map((item) => (
        <Card key={item.label} className="border-slate-200 bg-white">
          <CardHeader>
            <CardTitle className="text-sm leading-none text-slate-900">{item.value}</CardTitle>
            <CardDescription>{item.label}</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-slate-600">{item.description}</p>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

import { Head } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { ServiceSummaryCards } from '@/components/technical-service/ServiceSummaryCards'
import { ServiceFilters } from '@/components/technical-service/ServiceFilters'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { ServiceRequestTable } from '@/components/technical-service/ServiceRequestTable'
import type { ServiceFilters as FilterState, ServiceRequest, SummaryItem } from '@/components/technical-service/types'

const requests: ServiceRequest[] = [
  {
    id: 'req-001',
    mrn: 'MRN-1001',
    customer: 'Aleyna Elektronik',
    phone: '+90 532 123 45 67',
    city: 'İstanbul',
    district: 'Kadıköy',
    product: 'Philips DDL720 MVP',
    model: 'DDL720 MVP',
    serialNumber: 'SN12345678',
    channel: 'Yetkili Servis',
    serviceType: 'Montaj',
    technician: 'Usta Mehmet',
    appointment: '14 Mayıs 2026, 13:30',
    status: 'Randevulu',
    sla: '24 saat',
    address: 'Moda Mah. Bağdat Cad. No: 45',
    notes: 'Montaj için ekip hazır.',
    riskLevel: 'Orta',
  },
  {
    id: 'req-002',
    mrn: 'MRN-1002',
    customer: 'Güneş A.Ş.',
    phone: '+90 532 987 65 43',
    city: 'Ankara',
    district: 'Çankaya',
    product: 'Philips DDL702',
    model: 'DDL702',
    serialNumber: 'SN87654321',
    channel: 'Çağrı Merkezi',
    serviceType: 'Arıza',
    technician: 'Çilingir Hasan',
    appointment: '14 Mayıs 2026, 09:00',
    status: 'Devam Ediyor',
    sla: '12 saat',
    address: 'Tunali Hilmi Cad. No: 12',
    notes: 'Sistem düzgün çalışmıyor.',
    riskLevel: 'Yüksek',
  },
  {
    id: 'req-003',
    mrn: 'MRN-1003',
    customer: 'Narin Beyaz Eşya',
    phone: '+90 532 555 66 77',
    city: 'İzmir',
    district: 'Bornova',
    product: 'Philips DDL801',
    model: 'DDL801',
    serialNumber: 'SN11223344',
    channel: 'Online',
    serviceType: 'Kontrol',
    technician: 'Usta Ayşe',
    appointment: '15 Mayıs 2026, 11:00',
    status: 'Atandı',
    sla: '48 saat',
    address: 'Mimar Sinan Mah. 123. Sk. No: 8',
    notes: 'Periyodik kontrol talebi.',
    riskLevel: 'Düşük',
  },
  {
    id: 'req-004',
    mrn: 'MRN-1004',
    customer: 'Okan Tekstil',
    phone: '+90 532 222 33 44',
    city: 'Bursa',
    district: 'Nilüfer',
    product: 'Emaks Prime Galaxy 30',
    model: 'Galaxy 30',
    serialNumber: 'SN55667788',
    channel: 'Yetkili Servis',
    serviceType: 'Arıza',
    technician: 'Usta Hasan',
    appointment: '16 Mayıs 2026, 14:30',
    status: 'Yeni',
    sla: '72 saat',
    address: 'Gürsu Mah. Atatürk Cad. No: 17',
    notes: 'Kapanmama sorunu.',
    riskLevel: 'Orta',
  },
]

const initialFilters: FilterState = {
  search: '',
  serviceType: '',
  status: '',
}

export default function TechnicalService() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [selectedId, setSelectedId] = useState(requests[0].id)

  const filteredRequests = useMemo(() => {
    return requests.filter((request) => {
      const search = filters.search.toLowerCase().trim()
      const matchesSearch =
        !search ||
        [request.mrn, request.customer, request.phone, request.serialNumber]
          .some((value) => value.toLowerCase().includes(search))
      const matchesType = !filters.serviceType || request.serviceType === filters.serviceType
      const matchesStatus = !filters.status || request.status === filters.status

      return matchesSearch && matchesType && matchesStatus
    })
  }, [filters])

  const selectedRequest = requests.find((request) => request.id === selectedId) ?? requests[0]

  const summaryItems: SummaryItem[] = [
    {
      label: 'Açık Talep',
      value: String(requests.filter((request) => request.status !== 'Tamamlandı').length),
      tone: 'accent',
      description: 'Henüz tamamlanmamış teknik servis talepleri',
    },
    {
      label: 'Bugünkü Randevu',
      value: String(requests.filter((request) => request.appointment.includes('14 Mayıs 2026')).length),
      tone: 'warning',
      description: 'Bugün planlanmış servis ziyaretleri',
    },
    {
      label: 'SLA Riski',
      value: String(requests.filter((request) => request.riskLevel === 'Yüksek').length),
      tone: 'warning',
      description: 'SLA süresi yüksek riskli talepler',
    },
    {
      label: 'Tamamlanan İş',
      value: String(requests.filter((request) => request.status === 'Tamamlandı').length),
      tone: 'default',
      description: 'Bugüne kadar kapatılmış talepler',
    },
  ]

  return (
    <>
      <Head title="Teknik Servis" />

      <div className="space-y-6 px-4 py-6 md:px-6 lg:px-8">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <Heading
              title="Teknik Servis"
              description="Montaj ve servis taleplerini takip edin, SLA uyarılarını izleyin ve talep detaylarını görüntüleyin."
            />
          </div>
        </div>

        <ServiceSummaryCards items={summaryItems} />

        <ServiceFilters
          filters={filters}
          onChange={setFilters}
          onReset={() => setFilters(initialFilters)}
        />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(320px,1fr)]">
          <div className="space-y-4">
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Montaj / Servis Talepleri</p>
                  <p className="mt-1 text-sm text-slate-500">Toplam {filteredRequests.length} kayıt bulundu.</p>
                </div>
                <div className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  Liste
                </div>
              </div>
            </div>

            <ServiceRequestTable
              requests={filteredRequests}
              selectedId={selectedRequest.id}
              onSelect={(request) => setSelectedId(request.id)}
            />
          </div>

          <ServiceRequestDetails request={selectedRequest} />
        </div>
      </div>
    </>
  )
}

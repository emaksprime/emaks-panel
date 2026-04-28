import { Head } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import Heading from '@/components/heading'
import { ServiceSummaryCards } from '@/components/technical-service/ServiceSummaryCards'
import { ServiceFilters } from '@/components/technical-service/ServiceFilters'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { ServiceRequestTable } from '@/components/technical-service/ServiceRequestTable'
import type { ServiceFilters as FilterState, ServiceRequest, SummaryItem } from '@/components/technical-service/types'

type NewRequestForm = {
  customer: string
  phone: string
  city: string
  district: string
  address: string
  product: string
  serialNumber: string
  serviceType: string
  notes: string
}

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
    priority: 'Orta',
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
    priority: 'Yüksek',
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
    priority: 'Düşük',
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
    priority: 'Orta',
    technician: 'Usta Hasan',
    appointment: '16 Mayıs 2026, 14:30',
    status: 'Yeni',
    sla: '72 saat',
    address: 'Gürsu Mah. Atatürk Cad. No: 17',
    notes: 'Kapanmama sorunu.',
    riskLevel: 'Orta',
  },
  {
    id: 'req-005',
    mrn: 'MRN-1005',
    customer: 'Ege Teknik Servis',
    phone: '+90 536 444 55 66',
    city: 'Antalya',
    district: 'Muratpaşa',
    product: 'Philips DDL630',
    model: 'DDL630',
    serialNumber: 'SN99887766',
    channel: 'Çağrı Merkezi',
    serviceType: 'Arıza',
    priority: 'Kritik',
    technician: 'Atanmadı',
    appointment: '17 Mayıs 2026, 10:00',
    status: 'Yeni',
    sla: '18 saat',
    address: 'Çarşı Mah. 2059 Sk. No: 7',
    notes: 'Buzdolabı soğutmuyor.',
    riskLevel: 'Kritik',
  },
  {
    id: 'req-006',
    mrn: 'MRN-1006',
    customer: 'Pera Yapı',
    phone: '+90 532 111 22 33',
    city: 'İstanbul',
    district: 'Şişli',
    product: 'Emaks Prime XT',
    model: 'Prime XT',
    serialNumber: 'SN00998877',
    channel: 'Müşteri',
    serviceType: 'Montaj',
    priority: 'Düşük',
    technician: 'Usta Ali',
    appointment: '18 Mayıs 2026, 15:45',
    status: 'İptal',
    sla: 'N/A',
    address: 'Halaskargazi Cad. No: 12',
    notes: 'Müşteri iptal etti.',
    riskLevel: 'Düşük',
  },
]

const initialFilters: FilterState = {
  search: '',
  serviceType: '',
  status: '',
}

const initialRequestForm: NewRequestForm = {
  customer: '',
  phone: '',
  city: '',
  district: '',
  address: '',
  product: '',
  serialNumber: '',
  serviceType: '',
  notes: '',
}

export default function TechnicalService() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [selectedId, setSelectedId] = useState(requests[0].id)
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [createForm, setCreateForm] = useState<NewRequestForm>(initialRequestForm)

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

  const selectedRequest =
    filteredRequests.find((request) => request.id === selectedId) ??
    filteredRequests[0] ??
    requests[0]

  const summaryItems: SummaryItem[] = [
    {
      label: 'Açık Talep',
      value: String(requests.filter((request) => request.status !== 'Tamamlandı' && request.status !== 'İptal').length),
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
      value: String(requests.filter((request) => request.riskLevel === 'Yüksek' || request.riskLevel === 'Kritik').length),
      tone: 'warning',
      description: 'Yüksek ve kritik SLA riski olan talepler',
    },
    {
      label: 'Tamamlanan İş',
      value: String(requests.filter((request) => request.status === 'Tamamlandı').length),
      tone: 'default',
      description: 'Bugüne kadar kapatılmış talepler',
    },
    {
      label: 'Atanmamış Talep',
      value: String(requests.filter((request) => request.technician === 'Atanmadı').length),
      tone: 'default',
      description: 'Usta atanmamış talepler',
    },
  ]

  const handleCreateChange = (field: keyof NewRequestForm, value: string) => {
    setCreateForm((current) => ({ ...current, [field]: value }))
  }

  const handleCreateReset = () => {
    setCreateForm(initialRequestForm)
  }

  const handleCreateSubmit = () => {
    setIsDialogOpen(false)
    handleCreateReset()
  }

  return (
    <>
      <Head title="Teknik Servis" />

      <div className="mx-auto w-full max-w-[2200px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <Heading
              title="Teknik Servis"
              description="Montaj ve servis taleplerini takip edin, SLA uyarılarını izleyin ve talep detaylarını görüntüleyin."
            />
          </div>
          <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
            <DialogTrigger asChild>
              <Button type="button">Yeni Servis Talebi</Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Yeni Servis Talebi</DialogTitle>
                <DialogDescription>
                  Buradaki form şimdilik dummy veridir; talep kaydı lokal olarak yapılmayacaktır.
                </DialogDescription>
              </DialogHeader>
              <div className="grid gap-4 pt-2">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Müşteri adı
                    <Input
                      value={createForm.customer}
                      onChange={(event) => handleCreateChange('customer', event.target.value)}
                      placeholder="Müşteri adı"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Telefon
                    <Input
                      value={createForm.phone}
                      onChange={(event) => handleCreateChange('phone', event.target.value)}
                      placeholder="Telefon"
                    />
                  </label>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İl
                    <Input
                      value={createForm.city}
                      onChange={(event) => handleCreateChange('city', event.target.value)}
                      placeholder="İl"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İlçe
                    <Input
                      value={createForm.district}
                      onChange={(event) => handleCreateChange('district', event.target.value)}
                      placeholder="İlçe"
                    />
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Adres
                  <textarea
                    value={createForm.address}
                    onChange={(event) => handleCreateChange('address', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Adres"
                  />
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ürün
                    <Input
                      value={createForm.product}
                      onChange={(event) => handleCreateChange('product', event.target.value)}
                      placeholder="Ürün"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Seri No
                    <Input
                      value={createForm.serialNumber}
                      onChange={(event) => handleCreateChange('serialNumber', event.target.value)}
                      placeholder="Seri No"
                    />
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Servis Tipi
                  <select
                    value={createForm.serviceType}
                    onChange={(event) => handleCreateChange('serviceType', event.target.value)}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm outline-none transition focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                  >
                    <option value="">Seçiniz</option>
                    <option value="Montaj">Montaj</option>
                    <option value="Arıza">Arıza</option>
                    <option value="Kontrol">Kontrol</option>
                  </select>
                </label>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not
                  <textarea
                    value={createForm.notes}
                    onChange={(event) => handleCreateChange('notes', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Talep notu"
                  />
                </label>
              </div>
              <DialogFooter>
                <Button variant="outline" type="button" onClick={() => setIsDialogOpen(false)}>
                  İptal
                </Button>
                <Button type="button" onClick={handleCreateSubmit}>
                  Kaydet
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>

        <ServiceSummaryCards items={summaryItems} />

        <ServiceFilters
          filters={filters}
          onChange={setFilters}
          onReset={() => setFilters(initialFilters)}
        />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
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

          <div className="xl:self-start xl:sticky xl:top-28 xl:max-w-[400px]">
            <ServiceRequestDetails request={selectedRequest} />
          </div>
        </div>
      </div>
    </>
  )
}

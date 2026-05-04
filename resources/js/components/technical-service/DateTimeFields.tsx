import { Input } from '@/components/ui/input'
import {
  combineTechnicalServiceDateTime,
  toTechnicalServiceDateInputValue,
  toTechnicalServiceTimeInputValue,
} from './utils'

type DateTimeFieldsProps = {
  value: string
  onChange: (value: string) => void
  max?: string
}

const timePattern = '([01][0-9]|2[0-3]):[0-5][0-9]'

export function DateTimeFields({ value, onChange, max }: DateTimeFieldsProps) {
  const [rawDate = '', rawTime = ''] = value.split('T')
  const dateValue = /^\d{4}-\d{2}-\d{2}$/.test(rawDate) ? rawDate : value ? toTechnicalServiceDateInputValue(value) : ''
  const timeValue = rawTime ? rawTime.slice(0, 5) : value ? toTechnicalServiceTimeInputValue(value) : ''
  const maxDate = max ? toTechnicalServiceDateInputValue(max) : undefined

  const update = (nextDate: string, nextTime: string) => {
    onChange(combineTechnicalServiceDateTime(nextDate, nextTime))
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Input
        type="date"
        value={dateValue}
        max={maxDate}
        onChange={(event) => update(event.target.value, timeValue)}
      />
      <Input
        type="text"
        inputMode="numeric"
        placeholder="HH:mm"
        pattern={timePattern}
        value={timeValue}
        onChange={(event) => update(dateValue, event.target.value)}
      />
    </div>
  )
}

import { formatMoney } from '../../lib/money.js'
import ResourcePage from '../ResourcePage.jsx'

const config = {
  endpoint: '/api/v1/admin/card-types',
  idKey: 'card_type_id',
  title: 'Card types',
  description: 'The kinds of bank card that can be issued, with their daily spending limit.',
  singular: 'card type',
  emptyText: 'No card types yet. Add your first card type.',
  columns: [
    { key: 'type_name', label: 'Name' },
    { key: 'daily_limit', label: 'Daily limit', align: 'right', render: (row) => formatMoney(row.daily_limit) },
    { key: 'cards_count', label: 'Cards issued', align: 'right' },
  ],
  fields: [
    { name: 'type_name', label: 'Name', maxLength: 50, hint: 'For example, DEBIT.' },
    { name: 'daily_limit', label: 'Daily limit (GMD)', type: 'number', inputMode: 'decimal', min: 0.01, step: '0.01', hint: 'More than 0, up to 2 decimal places.' },
  ],
}

export default function CardTypesPage() {
  return <ResourcePage config={config} />
}

<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DocumentsExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $documents;
    protected string $currency;

    public function __construct($documents)
    {
        $this->documents = $documents;
        $this->currency = currencySign();
    }

    public function collection(): Collection
    {
        return $this->documents;
    }

    public function headings(): array
    {
        return [
            trans('admin/main.id'),
            trans('admin/main.title'),
            trans('admin/main.user'),
            trans('admin/main.system'),
            trans('admin/main.payment_source'),
            trans('admin/main.amount'),
            trans('admin/main.type'),
            trans('admin/main.type_account'),
            trans('public.date_time'),
            trans('admin/main.description'),
        ];
    }

    public function map($document): array
    {
        return [
            $document->id,
            $this->getTitle($document),
            $document->user->full_name ?? '',
            !empty($document->system) ? 'Y' : 'N',
            $this->getPaymentSource($document),
            $this->currency . '' . ($document->amount ?? 0),
            $document->type ?? '',
            $document->type_account ?? '',
            !empty($document->created_at) ? dateTimeFormat($document->created_at, 'j M Y H:i') : '',
            $document->description ?? '',
        ];
    }

    private function getPaymentSource($document): string
    {
        // Offline bank transfer top-up (approved by admin)
        if (!empty($document->description) && $document->description === trans('admin/pages/setting.notification_offline_payment_approved')) {
            return 'Offline payment';
        }

        // Wallet top-up via payment gateway (commonly Billplz in this project)
        if (!empty($document->description) && $document->description === trans('public.charge_account')) {
            return 'Billplz';
        }

        return '-';
    }

    private function getTitle($document): string
    {
        if (!empty($document->is_cashback)) {
            return trans('update.cashback');
        }

        if (!empty($document->webinar_id)) {
            return trans('admin/main.item_purchased');
        }

        if (!empty($document->bundle_id)) {
            return trans('update.bundle_purchased');
        }

        if (!empty($document->product_id)) {
            return trans('update.product_purchased') ?: 'Product purchased';
        }

        if (!empty($document->meeting_time_id)) {
            return trans('meeting.reservation_appointment');
        }

        if (!empty($document->subscribe_id)) {
            return trans('financial.subscribe');
        }

        if (!empty($document->promotion_id)) {
            return trans('panel.promotion');
        }

        if (!empty($document->registration_package_id)) {
            return trans('update.registration_package');
        }

        if (!empty($document->installment_payment_id)) {
            return trans('update.installment');
        }

        return trans('admin/main.document');
    }
}



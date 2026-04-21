<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class salesExport implements FromCollection, WithHeadings, WithMapping
{
    protected $sales;

    public function __construct($sales)
    {
        $this->sales = $sales;
    }

    /**
     * @return Collection
     */
    public function collection()
    {
        return $this->sales;
    }

    /**
     * @inheritDoc
     */
    public function headings(): array
    {
        return [
            trans('admin/main.id'),
            trans('admin/main.student'),
            trans('admin/main.mobile'),
            trans('admin/main.user_group'),
            trans('admin/main.student') . ' ' . trans('admin/main.id'),
            trans('admin/main.instructor'),
            trans('admin/main.instructor') . ' ' . trans('admin/main.id'),
            trans('admin/main.paid_amount'),
            trans('admin/main.item'),
            trans('admin/main.item') . ' ' . trans('admin/main.id'),
            trans('admin/main.sale_type'),
            trans('admin/main.date'),
            trans('admin/main.status'),
            trans('admin/main.affiliate'),
            trans('admin/main.referral_code'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function map($sale): array
    {

        if ($sale->payment_method == \App\Models\Sale::$subscribe) {
            $paidAmount = trans('admin/main.subscribe');
        } else {
            if (!empty($sale->total_amount)) {
                $paidAmount = handlePrice($sale->total_amount);
            } else {
                $paidAmount = trans('public.free');
            }
        }

        $status = $sale->getAdminFinancialSalesListStatusText();

        // Get affiliate and referral code data (only for subscribe sales)
        $affiliateName = '';
        $affiliateId = '';
        $referralCode = '';
        
        if ($sale->type == \App\Models\Sale::$subscribe) {
            if (!empty($sale->affiliate_user)) {
                $affiliateName = $sale->affiliate_user->full_name ?? '';
                $affiliateId = $sale->affiliate_user->id ?? '';
            }
            $referralCode = $sale->affiliate_code ?? '';
        }

        return [
            $sale->id,
            !empty($sale->buyer) ? $sale->buyer->full_name : 'Deleted User',
            !empty($sale->buyer) ? $sale->buyer->mobile : '',
            (!empty($sale->buyer) && !empty($sale->buyer->userGroup) && !empty($sale->buyer->userGroup->group)) ? $sale->buyer->userGroup->group->name : '',
            !empty($sale->buyer) ? $sale->buyer->id : 'Deleted User',
            $sale->item_seller,
            $sale->seller_id,
            $paidAmount,
            $sale->item_title,
            $sale->item_id,
            trans('admin/main.' . $sale->type),
            dateTimeFormat($sale->created_at, 'j M Y H:i'),
            $status,
            $affiliateName . (!empty($affiliateId) ? ' (ID: ' . $affiliateId . ')' : ''),
            $referralCode
        ];
    }
}

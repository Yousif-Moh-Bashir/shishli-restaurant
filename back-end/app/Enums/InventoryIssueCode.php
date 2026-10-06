<?php

namespace App\Enums;

enum InventoryIssueCode: string
{
    case InventoryNotConfigured = 'INVENTORY_NOT_CONFIGURED';
    case OutOfStock = 'OUT_OF_STOCK';
    case InsufficientStock = 'INSUFFICIENT_STOCK';
    case InvalidStockAdjustment = 'INVALID_STOCK_ADJUSTMENT';

    public function message(): string
    {
        return match ($this) {
            self::InventoryNotConfigured => 'مخزون المنتج غير مهيأ لهذا الفرع.',
            self::OutOfStock => 'المنتج غير متوفر حاليًا.',
            self::InsufficientStock => 'الكمية المطلوبة غير متوفرة حاليًا.',
            self::InvalidStockAdjustment => 'تعديل المخزون غير صالح أو يؤثر على الكمية المحجوزة.',
        };
    }
}

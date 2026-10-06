<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case StockIn = 'stock_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case Sale = 'sale';
    case Return = 'return';
    case Waste = 'waste';
}

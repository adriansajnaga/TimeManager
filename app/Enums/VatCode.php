<?php

namespace App\Enums;

/**
 * Oznaczenie stawki VAT w pozycji faktury — wartości pola P_12 schemy FA(3).
 * Mapowanie na pola sum (P_13_x) i adnotacje powstaje w fazie KSeF, po weryfikacji z XSD.
 */
enum VatCode: string
{
    case Rate23 = '23';
    case Rate8 = '8';
    case Rate5 = '5';
    case ZeroDomestic = '0 KR';
    case ZeroIntraEu = '0 WDT';
    case ZeroExport = '0 EX';
    case Exempt = 'zw';
    case ReverseCharge = 'oo';
    case OutsideScope = 'np I';
    case OutsideScopeEuServices = 'np II';

    public function label(): string
    {
        return match ($this) {
            self::Rate23 => '23%',
            self::Rate8 => '8%',
            self::Rate5 => '5%',
            self::ZeroDomestic => __('0% domestic'),
            self::ZeroIntraEu => __('0% intra-EU supply of goods'),
            self::ZeroExport => __('0% export'),
            self::Exempt => __('Exempt'),
            self::ReverseCharge => __('Reverse charge (domestic)'),
            self::OutsideScope => __('Not subject to VAT in Poland'),
            self::OutsideScopeEuServices => __('Not subject to VAT in Poland – EU services (art. 100 sec. 1 item 4)'),
        };
    }

    /**
     * Stawka procentowa, gdy pozycja jest opodatkowana kwotowo; null dla zw/oo/np i stawek 0%.
     */
    public function percent(): ?int
    {
        return match ($this) {
            self::Rate23 => 23,
            self::Rate8 => 8,
            self::Rate5 => 5,
            default => null,
        };
    }
}

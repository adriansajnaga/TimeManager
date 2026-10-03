<?php

namespace App\Services\Invoices;

use App\Enums\VatCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Zestawienie faktury według stawek VAT (pola P_13_x / P_14_x w FA(3)).
 * Podatek liczony od sumy wartości netto w danej stawce.
 */
final class VatSummary
{
    /**
     * @param  list<array{code: VatCode, net: BigDecimal, vat: BigDecimal}>  $groups  w kolejności stawek z FA(3)
     */
    private function __construct(private readonly array $groups) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<array{net: BigDecimal|string, vat_code: VatCode|string}>  $lines
     */
    public static function fromLines(iterable $lines): self
    {
        $nets = [];

        foreach ($lines as $line) {
            $code = $line['vat_code'] instanceof VatCode ? $line['vat_code'] : VatCode::from($line['vat_code']);
            $nets[$code->name] = ($nets[$code->name] ?? BigDecimal::zero())->plus($line['net']);
        }

        $groups = [];

        foreach (VatCode::cases() as $code) {
            if (isset($nets[$code->name])) {
                $groups[] = ['code' => $code, 'net' => $nets[$code->name], 'vat' => self::vatOf($nets[$code->name], $code)];
            }
        }

        return new self($groups);
    }

    /**
     * Faktura zaliczkowa: otrzymana kwota brutto rozdzielona na stawki proporcjonalnie do zamówienia,
     * podatek wyliczony „od brutto” (kwota × stawka / (100 + stawka)).
     */
    public static function advance(self $order, BigDecimal|string $gross): self
    {
        $gross = BigDecimal::of($gross);
        $orderGross = $order->gross();

        if ($order->groups === [] || $orderGross->isZero()) {
            return self::empty();
        }

        $last = count($order->groups) - 1;
        $remaining = $gross;
        $groups = [];

        foreach ($order->groups as $index => $group) {
            $groupGross = $index === $last
                ? $remaining
                : $gross->multipliedBy($group['net']->plus($group['vat']))->dividedBy($orderGross, 2, RoundingMode::HalfUp);
            $remaining = $remaining->minus($groupGross);

            $percent = $group['code']->percent() ?? 0;
            $net = $groupGross->multipliedBy(100)->dividedBy(100 + $percent, 2, RoundingMode::HalfUp);

            $groups[] = ['code' => $group['code'], 'net' => $net, 'vat' => $groupGross->minus($net)];
        }

        return new self($groups);
    }

    public function plus(self $other): self
    {
        return $this->combine($other, 1);
    }

    public function minus(self $other): self
    {
        return $this->combine($other, -1);
    }

    /**
     * @return list<array{code: VatCode, net: BigDecimal, vat: BigDecimal, gross: BigDecimal}>
     */
    public function rows(): array
    {
        return array_map(fn (array $group) => [...$group, 'gross' => $group['net']->plus($group['vat'])], $this->groups);
    }

    /**
     * @return list<VatCode>
     */
    public function codes(): array
    {
        return array_map(fn (array $group) => $group['code'], $this->groups);
    }

    public function net(): BigDecimal
    {
        return array_reduce($this->groups, fn (BigDecimal $sum, array $group) => $sum->plus($group['net']), BigDecimal::zero()->toScale(2));
    }

    public function vat(): BigDecimal
    {
        return array_reduce($this->groups, fn (BigDecimal $sum, array $group) => $sum->plus($group['vat']), BigDecimal::zero()->toScale(2));
    }

    public function gross(): BigDecimal
    {
        return $this->net()->plus($this->vat());
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    public function hasReverseCharge(): bool
    {
        foreach ($this->codes() as $code) {
            if ($code->isReverseCharge()) {
                return true;
            }
        }

        return false;
    }

    public static function vatOf(BigDecimal|string $net, VatCode $code): BigDecimal
    {
        $percent = $code->percent();

        return $percent === null
            ? BigDecimal::zero()->toScale(2)
            : BigDecimal::of($net)->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    /**
     * Suma lub różnica dwóch zestawień, stawka po stawce (bez ponownego liczenia podatku).
     */
    private function combine(self $other, int $sign): self
    {
        $zero = BigDecimal::zero()->toScale(2);
        $groups = [];

        foreach (VatCode::cases() as $code) {
            $mine = $this->group($code);
            $theirs = $other->group($code);

            if ($mine === null && $theirs === null) {
                continue;
            }

            $groups[] = [
                'code' => $code,
                'net' => ($mine['net'] ?? $zero)->plus(($theirs['net'] ?? $zero)->multipliedBy($sign)),
                'vat' => ($mine['vat'] ?? $zero)->plus(($theirs['vat'] ?? $zero)->multipliedBy($sign)),
            ];
        }

        return new self($groups);
    }

    /**
     * @return array{code: VatCode, net: BigDecimal, vat: BigDecimal}|null
     */
    private function group(VatCode $code): ?array
    {
        foreach ($this->groups as $group) {
            if ($group['code'] === $code) {
                return $group;
            }
        }

        return null;
    }
}

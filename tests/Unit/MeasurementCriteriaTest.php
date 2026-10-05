<?php

use App\Enums\ProtectionType;
use App\Enums\RcdType;
use App\Services\Measurements\Criteria;

test('loop impedance values match the protocols: B16, C25 and gG fuses', function () {
    // PROT/1/2/2025, 2F4 B16: Ia = 80 A, Za = 2,875 Ω, Zs 1,36 Ω → Ik 169 A.
    $ia = Criteria::tripCurrent(ProtectionType::B, 16, 0.4);
    expect($ia)->toBe(80.0)
        ->and(round((float) Criteria::allowedImpedance(230, $ia), 3))->toBe(2.875)
        ->and((int) round((float) Criteria::shortCircuitCurrent(230, 1.36)))->toBe(169)
        ->and(Criteria::loopPasses(1.36, 2.875))->toBeTrue();

    // WLZ C25: Ia = 250 A, Za = 0,92 Ω.
    expect(round((float) Criteria::allowedImpedance(230, Criteria::tripCurrent(ProtectionType::C, 25, 0.4)), 2))->toBe(0.92)
        ->and(Criteria::tripCurrent(ProtectionType::D, 10, 0.4))->toBe(200.0);

    // Wkładki gG z tabeli dla 0,4 s i 5 s; spoza tabeli — trzeba wpisać Ia ręcznie.
    expect(Criteria::tripCurrent(ProtectionType::GG, 25, 0.4))->toBe(180.0)
        ->and(Criteria::tripCurrent(ProtectionType::GG, 160, 5))->toBe(950.0)
        ->and(Criteria::tripCurrent(ProtectionType::GG, 160, 0.4))->toBeNull()
        ->and(Criteria::tripCurrent(ProtectionType::GG, 160, 0.4, override: 1500))->toBe(1500.0);

    expect(Criteria::loopPasses(3.1, 2.875))->toBeFalse()
        ->and(Criteria::loopPasses(null, 2.875))->toBeNull();
});

test('insulation readings like ">30" are understood and compared with the required value', function () {
    expect(Criteria::insulationValue('>30'))->toBe(30.0)
        ->and(Criteria::insulationValue('> 1000'))->toBe(1000.0)
        ->and(Criteria::insulationValue('0,8'))->toBe(0.8)
        ->and(Criteria::insulationValue(''))->toBeNull()
        ->and(Criteria::requiredInsulation(250))->toBe(0.5)
        ->and(Criteria::requiredInsulation(500))->toBe(1.0)
        ->and(Criteria::insulationPasses('>30', 1.0))->toBeTrue()
        ->and(Criteria::insulationPasses('0,8', 1.0))->toBeFalse();
});

test('RCD results are checked for time, trip current, contact voltage and the TEST button', function () {
    // Fi1 z protokołu: typ A, 30 mA, 18,3 ms, 24 mA.
    expect(Criteria::rcdFailures(RcdType::A, false, 30, 18.3, 24.0, 0.5, 50, true))->toBe([])
        ->and(Criteria::rcdFailures(RcdType::A, false, 30, 320, 24.0, null, 50, true))->toBe(['time'])
        ->and(Criteria::rcdFailures(RcdType::AC, false, 30, 20, 31.0, null, 50, true))->toBe(['current'])
        ->and(Criteria::rcdFailures(RcdType::A, true, 30, 100, 24.0, null, 50, true))->toBe(['time'])
        ->and(Criteria::rcdFailures(RcdType::A, false, 30, 20, 24.0, 60, 50, false))->toBe(['contact_voltage', 'test_button'])
        ->and(Criteria::rcdFailures(RcdType::A, false, 30, null, null, null, 50, true))->toBeNull();
});

test('earthing uses the correction factor and continuity an optional limit', function () {
    // Uziom fundamentowy: 5,5 Ω × 1,3 = 7,15 Ω ≤ 10 Ω.
    expect(Criteria::earthingPasses(5.5, 1.3, 10))->toBeTrue()
        ->and(Criteria::earthingPasses(8.0, 1.3, 10))->toBeFalse()
        ->and(Criteria::continuityPasses(0.12, null))->toBeTrue()
        ->and(Criteria::continuityPasses(0.9, 0.5))->toBeFalse();
});
